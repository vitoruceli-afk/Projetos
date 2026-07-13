<?php
$auth = new \Core\Auth();

// Verificar se está logado
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?page=login');
    exit;
}

$user_atual = $auth->getCurrentUser();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Manutenção</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #333;
        }

        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .navbar h1 {
            font-size: 1.5rem;
            margin: 0;
        }

        .navbar-user {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .navbar-user a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
            transition: background 0.3s;
        }

        .navbar-user a:hover {
            background: rgba(255,255,255,0.3);
        }

        .container {
            display: grid;
            grid-template-columns: 200px 1fr;
            min-height: calc(100vh - 80px);
        }

        .sidebar {
            background: white;
            padding: 1.5rem;
            border-right: 1px solid #ddd;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow-y: auto;
        }

        .sidebar h3 {
            color: #667eea;
            font-size: 0.9rem;
            text-transform: uppercase;
            margin-top: 1.5rem;
            margin-bottom: 0.75rem;
            border-bottom: 2px solid #667eea;
            padding-bottom: 0.5rem;
        }

        .sidebar h3:first-child {
            margin-top: 0;
        }

        .sidebar ul {
            list-style: none;
        }

        .sidebar ul li {
            margin: 0.5rem 0;
        }

        .sidebar ul li a {
            color: #333;
            text-decoration: none;
            padding: 0.5rem 0.75rem;
            border-radius: 4px;
            display: block;
            transition: background 0.3s, color 0.3s;
        }

        .sidebar ul li a:hover {
            background: #f0f0f0;
            color: #667eea;
        }

        .sidebar ul li a.active {
            background: #667eea;
            color: white;
        }

        .content {
            padding: 2rem;
            overflow-y: auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #ddd;
        }

        .page-header h1 {
            font-size: 2rem;
            color: #333;
        }

        .page-header p {
            color: #7f8c8d;
            margin: 0.5rem 0 0 0;
        }

        .btn {
            background: #667eea;
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #5568d3;
        }

        .btn-danger {
            background: #e74c3c;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 4px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        table thead {
            background: #f5f5f5;
            border-bottom: 2px solid #ddd;
        }

        table th, table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        table tr:hover {
            background: #f9f9f9;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        footer {
            background: #2c3e50;
            color: white;
            text-align: center;
            padding: 1.5rem;
            margin-top: 2rem;
            border-top: 1px solid #ddd;
        }

        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
            }

            .sidebar {
                display: none;
            }

            .navbar {
                flex-direction: column;
                gap: 1rem;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🔧 Sistema de Manutenção</h1>
        <div class="navbar-user">
            <span>Bem-vindo, <strong><?php echo htmlspecialchars($user_atual['full_name'] ?? $user_atual['username']); ?></strong>!</span>
            <a href="index.php?page=logout">Sair</a>
        </div>
    </div>

    <div class="container">
        <div class="sidebar">
            <!-- MENU -->
            <h3>📊 MENU</h3>
            <ul>
                <li><a href="index.php?page=dashboard">Dashboard</a></li>
            </ul>

            <!-- ADMINISTRAÇÃO -->
            <h3>⚙️ ADMINISTRAÇÃO</h3>
            <ul>
                <li><a href="index.php?page=users">Usuários</a></li>
                <li><a href="index.php?page=profiles">Perfis</a></li>
                <li><a href="index.php?page=smtp">SMTP</a></li>
            </ul>

            <!-- OPERAÇÕES -->
            <h3>📋 OPERAÇÕES</h3>
            <ul>
                <li><a href="index.php?page=forms">Formulários</a></li>
                <li><a href="index.php?page=submissions">Preenchimentos</a></li>
                <li><a href="index.php?page=sectors">Setores</a></li>
                <li><a href="index.php?page=devices">🖥️ Dispositivos</a></li>
            </ul>

            <!-- RELATÓRIOS -->
            <h3>📈 RELATÓRIOS</h3>
            <ul>
                <li><a href="index.php?page=reports">Relatórios</a></li>
            </ul>

            <!-- CONTA -->
            <h3>👤 CONTA</h3>
            <ul>
                <li><a href="index.php?page=profile">Meu Perfil</a></li>
                <li><a href="index.php?page=logout">Sair</a></li>
            </ul>
        </div>

        <div class="content">
