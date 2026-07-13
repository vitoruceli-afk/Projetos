<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>📋 Formulários</h1>
    <a href="?page=forms&action=create" class="btn">+ Novo Formulário</a>
</div>

<?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
    <div class="alert alert-success">
        ✅ Operação realizada com sucesso!
    </div>
<?php endif; 

if (isset($_GET['error'])): ?>
    <div class="alert alert-danger">
        ❌ Erro: <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
<?php endif; ?>

<?php if (!empty($forms)): ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nome</th>
                <th>Descrição</th>
                <th>Status</th>
                <th>Criado em</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($forms as $form): ?>
                <tr>
                    <td><?php echo htmlspecialchars($form['id']); ?></td>
                    <td><strong><?php echo htmlspecialchars($form['name']); ?></strong></td>
                    <td><?php echo htmlspecialchars(substr($form['description'] ?? '', 0, 50)); ?></td>
                    <td>
                        <?php if ($form['status'] === 'active'): ?>
                            <span class="badge badge-success">Ativo</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('d/m/Y H:i', strtotime($form['created_at'] ?? '')); ?></td>
                    <td style="white-space: nowrap;">
                        <a href="?page=forms&action=builder&id=<?php echo $form['id']; ?>">Builder</a> |
                        <a href="?page=forms&action=edit&id=<?php echo $form['id']; ?>">Editar</a> |
                        <a href="?page=forms&action=delete&id=<?php echo $form['id']; ?>" onclick="return confirm('Tem certeza?')">Deletar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="alert alert-info">
        Nenhum formulário encontrado. <a href="?page=forms&action=create">Crie um novo formulário</a>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>