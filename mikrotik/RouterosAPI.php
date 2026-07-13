<?php
class RouterosAPI {
    public $debug = false;
    public $connected = false;
    public $port = 8728;
    public $timeout = 2;
    public $attempts = 1;
    public $delay = 1;
    
    private $socket;

    public function connect($ip, $login, $password) {
        for ($attempts = 1; $attempts <= $this->attempts; $attempts++) {
            $this->socket = @fsockopen($ip, $this->port, $err, $errstr, $this->timeout);
            if ($this->socket) {
                socket_set_timeout($this->socket, $this->timeout);
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=password=' . $password);
                
                $response = $this->read(false);
                
                if (isset($response[0]) && $response[0] == '!done') {
                    $this->connected = true;
                    return true;
                }
                
                // Trata autenticação baseada em MDM/CHAP (RouterOS v6 antigo)
                if (isset($response[0]) && floatval(substr($response[0], 5)) >= 6.43) {
                    $this->write('/login', false);
                    $this->write('=name=' . $login, false);
                    $this->write('=password=' . $password);
                    $response = $this->read(false);
                }
                
                fclose($this->socket);
            }
            sleep($this->delay);
        }
        return false;
    }

    public function disconnect() {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->connected = false;
    }

    // Testa a alcançabilidade TCP de vários roteadores em paralelo (conexões não-bloqueantes),
    // em vez de tentar um por um sequencialmente. $targets = [chave => ['host' => .., 'port' => ..]].
    // Retorna [chave => bool]. Usado para descartar rapidamente roteadores offline antes de
    // gastar tempo tentando login completo em cada um.
    public static function checkHostsAlive(array $targets, $timeout = 1.5) {
        $sockets = [];
        $results = [];

        foreach ($targets as $key => $target) {
            $host = $target['host'];
            $port = $target['port'] ?? 8728;
            $errno = 0;
            $errstr = '';
            $sock = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_ASYNC_CONNECT
            );
            if ($sock) {
                $sockets[$key] = $sock;
            } else {
                $results[$key] = false;
            }
        }

        $deadline = microtime(true) + $timeout;
        while (!empty($sockets) && microtime(true) < $deadline) {
            $read = [];
            $write = array_values($sockets);
            $except = [];
            $remaining = max(0, $deadline - microtime(true));
            $sec = (int) $remaining;
            $usec = (int) (($remaining - $sec) * 1000000);
            $changed = @stream_select($read, $write, $except, $sec, $usec);
            if ($changed === false || $changed === 0) {
                break;
            }
            foreach ($sockets as $key => $sock) {
                if (in_array($sock, $write, true)) {
                    $results[$key] = @stream_socket_get_name($sock, true) !== false;
                    fclose($sock);
                    unset($sockets[$key]);
                }
            }
        }

        foreach ($sockets as $key => $sock) {
            $results[$key] = false;
            @fclose($sock);
        }

        return $results;
    }

    public function comm($com, $arr = array()) {
        $count = count($arr);
        $this->write($com, ($count == 0));
        $i = 0;
        foreach ($arr as $k => $v) {
            $this->write('=' . $k . '=' . $v, (++$i == $count));
        }
        return $this->read();
    }

    public function write($command, $end = true) {
        $lines = explode("\n", $command);
        foreach ($lines as $line) {
            $line = trim($line);
            fwrite($this->socket, $this->encodeLength(strlen($line)) . $line);
        }
        if ($end) {
            fwrite($this->socket, chr(0));
        }
    }

    // O SEGREDOR DO COMPORTAMENTO: Loop de leitura corrigido em lote
    public function read($parse = true) {
        $response = array();
        while (true) {
            $byte = ord(fread($this->socket, 1));
            $length = 0;
            if ($byte & 128) {
                if (($byte & 192) == 128) {
                    $length = (($byte & 63) << 8) + ord(fread($this->socket, 1));
                } else {
                    if (($byte & 224) == 192) {
                        $length = (($byte & 31) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
                    } else {
                        if (($byte & 240) == 224) {
                            $length = (($byte & 15) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
                        } else {
                            $length = (ord(fread($this->socket, 1)) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
                        }
                    }
                }
            } else {
                $length = $byte;
            }

            if ($length > 0) {
                $_read = "";
                while (strlen($_read) < $length) {
                    $_read .= fread($this->socket, $length - strlen($_read));
                }
                $response[] = $_read;
            } else {
                // Se encontrou uma quebra definitiva e o buffer acabou, encerra a captura
                if (empty($response)) {
                    break;
                }
                
                // Captura a última palavra de controle da lista (!done)
                $last_word = end($response);
                if ($last_word == '!done' || $last_word == '!fatal') {
                    break;
                }
            }
        }

        if ($parse) {
            return $this->parseResponse($response);
        }
        return $response;
    }

    private function encodeLength($length) {
        if ($length < 128) {
            return chr($length);
        } elseif ($length < 16384) {
            return chr(($length >> 8) | 128) . chr($length & 255);
        } elseif ($length < 2097152) {
            return chr(($length >> 16) | 192) . chr(($length >> 8) & 255) . chr($length & 255);
        } elseif ($length < 268435456) {
            return chr(($length >> 24) | 224) . chr(($length >> 16) & 255) . chr(($length >> 8) & 255) . chr($length & 255);
        }
    }

    private function parseResponse($response) {
        $parsed = array();
        $current = array();
        foreach ($response as $word) {
            if ($word == '!re') {
                if (!empty($current)) {
                    $parsed[] = $current;
                }
                $current = array();
            } elseif (substr($word, 0, 1) == '=') {
                $parts = explode('=', substr($word, 1), 2);
                if (count($parts) == 2) {
                    $current[$parts[0]] = $parts[1];
                }
            }
        }
        if (!empty($current)) {
            $parsed[] = $current;
        }
        return $parsed;
    }
}
?>