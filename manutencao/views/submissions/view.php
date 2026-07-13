<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>👁️ Visualizar Preenchimento</h1>
</div>

<?php if ($submission): ?>
    <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h2><?php echo htmlspecialchars($submission['form_name']); ?></h2>
        
        <div style="margin: 1.5rem 0; padding: 1rem; background: #f9f9f9; border-radius: 4px;">
            <p><strong>Preenchido por:</strong> <?php echo htmlspecialchars($submission['user_name']); ?></p>
            <p><strong>Data:</strong> <?php echo date('d/m/Y H:i:s', strtotime($submission['submitted_at'])); ?></p>
            <p><strong>Status:</strong> 
                <?php if ($submission['status'] === 'active'): ?>
                    <span class="badge badge-success">Ativo</span>
                <?php else: ?>
                    <span class="badge badge-danger">Inativo</span>
                <?php endif; ?>
            </p>
        </div>

        <h3>Respostas</h3>
        <table>
            <thead>
                <tr>
                    <th>Campo</th>
                    <th>Resposta</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($answers)): ?>
                    <?php foreach ($answers as $answer): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($answer['label']); ?></strong></td>
                            <td><?php echo htmlspecialchars($answer['answer_value']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="2" style="text-align: center; color: #7f8c8d;">Nenhuma resposta registrada</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-top: 2rem; display: flex; gap: 1rem;">
            <a href="?page=submissions&action=delete&id=<?php echo $submission['id']; ?>" 
               class="btn btn-danger"
               onclick="return confirm('Tem certeza que deseja deletar?')">🗑️ Deletar</a>
            <a href="?page=submissions" class="btn" style="background: #95a5a6; text-decoration: none;">⬅️ Voltar</a>
        </div>
    </div>

<?php else: ?>
    <div class="alert alert-danger">
        ❌ Preenchimento não encontrado!
    </div>
    <a href="?page=submissions" class="btn">Voltar</a>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>