<?php
header('Content-Type: application/json');
session_start();

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(function ($class) {
    $paths = [
        BASE_PATH . '/config/',
        BASE_PATH . '/core/',
        BASE_PATH . '/models/',
        BASE_PATH . '/controllers/'
    ];
    
    foreach ($paths as $path) {
        $file = $path . str_replace('\\', DIRECTORY_SEPARATOR, strrchr($class, '\\')) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

require_once BASE_PATH . '/config/Config.php';
require_once BASE_PATH . '/config/Database.php';

\Config\Config::init();

try {
    $action = $_GET['action'] ?? '';
    $db = \Config\Database::getInstance();
    
    if (!isset($_SESSION['user_id'])) {
        throw new \Exception('Não autenticado');
    }

    switch ($action) {
        case 'addField':
            $data = json_decode(file_get_contents('php://input'), true);
            
            $field_id = $db->insert('form_fields', [
                'form_id' => intval($data['form_id']),
                'section_id' => intval($data['section_id'] ?? 0),
                'name' => strtolower(str_replace(' ', '_', $data['label'])),
                'label' => $data['label'],
                'field_type' => $data['field_type'],
                'placeholder' => $data['placeholder'] ?? '',
                'help_text' => $data['help_text'] ?? '',
                'is_required' => $data['is_required'] ?? 0,
                'order_number' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            echo json_encode(['success' => true, 'field_id' => $field_id]);
            break;

        case 'deleteField':
            $data = json_decode(file_get_contents('php://input'), true);
            $db->delete('form_fields', 'id = ?', [$data['field_id']]);
            echo json_encode(['success' => true]);
            break;

        case 'getFields':
            $form_id = intval($_GET['form_id'] ?? 0);
            $fields = $db->fetchAll(
                "SELECT * FROM form_fields WHERE form_id = ? ORDER BY order_number ASC",
                [$form_id]
            );
            echo json_encode(['success' => true, 'fields' => $fields]);
            break;

        case 'addSection':
            $data = json_decode(file_get_contents('php://input'), true);
            
            $section_id = $db->insert('form_sections', [
                'form_id' => intval($data['form_id']),
                'title' => $data['title'],
                'description' => $data['description'] ?? '',
                'columns' => intval($data['columns'] ?? 1),
                'order_number' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            echo json_encode(['success' => true, 'section_id' => $section_id]);
            break;

        case 'updateSection':
            $data = json_decode(file_get_contents('php://input'), true);
            
            $db->update('form_sections', [
                'title' => $data['title'],
                'description' => $data['description'] ?? '',
                'columns' => intval($data['columns'] ?? 1)
            ], 'id = ?', [$data['section_id']]);
            
            echo json_encode(['success' => true]);
            break;

        case 'deleteSection':
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Deletar campos da seção
            $db->delete('form_fields', 'section_id = ?', [$data['section_id']]);
            // Deletar seção
            $db->delete('form_sections', 'id = ?', [$data['section_id']]);
            
            echo json_encode(['success' => true]);
            break;

        case 'getFormData':
            $form_id = intval($_GET['form_id'] ?? 0);
            
            $sections = $db->fetchAll(
                "SELECT * FROM form_sections WHERE form_id = ? ORDER BY order_number ASC",
                [$form_id]
            );
            
            $fields = $db->fetchAll(
                "SELECT * FROM form_fields WHERE form_id = ? ORDER BY order_number ASC",
                [$form_id]
            );
            
            echo json_encode([
                'success' => true,
                'sections' => $sections,
                'fields' => $fields
            ]);
            break;

        default:
            throw new \Exception('Ação não encontrada');
    }
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
?>