<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>🔐 Perfis de Usuário</h1>
    <a href="?page=profiles&action=create" class="btn">+ Novo Perfil</a>
</div>

<?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
    <div class="alert alert-success">
        ✅ Operação realizada com sucesso!
    </div>
<?php endif; 

if (isset($_GET['error'])): ?>
    <div class="alert alert-danger">
        ❌ Erro: 
        <?php 
        $errors = [
            'perfil_em_uso' => 'Este perfil está em uso e não pode ser deletado'
        ];
        echo isset($errors[$_GET['error']]) ? $errors[$_GET['error']] : htmlspecialchars($_GET['error']);
        ?>
    </div>
<?php endif; ?>

<?php if (!empty($profiles)): ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Descrição</th>
                <th>Padrão</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($profiles as $profile): ?>
                <tr>
                    <td><?php echo htmlspecialchars($profile['id']); ?></td>
                    <td><strong><?php echo htmlspecialchars($profile['name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($profile['description'] ?? '-'); ?></td>
                    <td>
                        <?php if ($profile['is_default']): ?>
                            <span class="badge badge-info">Sim</span>
                        <?php else: ?>
                            <span class="badge">Não</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space: nowrap;">
                        <a href="?page=profiles&action=permissions&id=<?php echo $profile['id']; ?>">Permissões</a> |
                        <a href="?page=profiles&action=edit&id=<?php echo $profile['id']; ?>">Editar</a> |
                        <a href="?page=profiles&action=delete&id=<?php echo $profile['id']; ?>" onclick="return confirm('Tem certeza?')">Deletar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="alert alert-info">
        Nenhum perfil encontrado. <a href="?page=profiles&action=create">Crie um novo perfil</a>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>