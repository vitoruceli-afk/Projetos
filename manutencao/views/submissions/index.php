<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>📤 Preenchimentos de Formulários</h1>
    <a href="?page=submissions&action=create" class="btn">+ Novo Preenchimento</a>
</div>

<?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
    <div class="alert alert-success">
        ✅ Operação realizada com sucesso!
    </div>
<?php endif; ?>

<?php if (!empty($_GET['glpi_warning'])): ?>
    <div class="alert alert-warning">
        ⚠️ <?php echo htmlspecialchars($_GET['glpi_warning']); ?>
    </div>
<?php endif; ?>

<?php if (!empty($submissions)): ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Formulário</th>
                <th>Preenchido Por</th>
                <th>Data</th>
                <th>Status</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($submissions as $sub): ?>
                <tr>
                    <td><?php echo htmlspecialchars($sub['id']); ?></td>
                    <td><?php echo htmlspecialchars($sub['form_name']); ?></td>
                    <td><?php echo htmlspecialchars($sub['user_name']); ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($sub['submitted_at'])); ?></td>
                    <td>
                        <?php if ($sub['status'] === 'active'): ?>
                            <span class="badge badge-success">Ativo</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space: nowrap;">
                        <a href="?page=submissions&action=view&id=<?php echo $sub['id']; ?>">Visualizar</a> |
                        <a href="?page=submissions&action=delete&id=<?php echo $sub['id']; ?>" onclick="return confirm('Tem certeza?')">Deletar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="alert alert-info">
        Nenhum preenchimento encontrado. <a href="?page=submissions&action=create">Crie um novo</a>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>