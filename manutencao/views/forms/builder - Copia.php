<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>🔧 Builder - <?php echo htmlspecialchars($form['name']); ?></h1>
    <p>Crie e organize os campos do seu formulário de forma visual</p>
</div>

<div style="background: white; padding: 2rem; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
    <div style="display: flex; gap: 1rem; margin-bottom: 2rem;">
        <button id="btn-add-field" class="btn" style="background: #667eea;">➕ Adicionar Campo</button>
        <button id="btn-add-section" class="btn" style="background: #3498db;">📑 Adicionar Seção</button>
        <a href="?page=forms&action=edit&id=<?php echo $form['id']; ?>" class="btn" style="background: #f39c12; text-decoration: none;">✏️ Editar Formulário</a>
        <a href="?page=forms" class="btn" style="background: #95a5a6; text-decoration: none;">⬅️ Voltar</a>
    </div>

    <h3>📝 Campos do Formulário</h3>
    <div id="fieldsContainer">
        <p style="color: #7f8c8d;">Carregando campos...</p>
    </div>
</div>

<div style="margin-top: 2rem; background: #f0f7ff; padding: 1.5rem; border-radius: 4px; border-left: 4px solid #3498db;">
    <h3>💡 Dicas para Criar Formulários</h3>
    <ul style="margin: 1rem 0; padding-left: 1.5rem;">
        <li><strong>Organize em Seções:</strong> Use seções para agrupar campos relacionados</li>
        <li><strong>Use Colunas:</strong> Configure múltiplas colunas para aproveitar melhor o espaço</li>
        <li><strong>Escolha Tipos Adequados:</strong> Use o tipo de campo mais apropriado (data, email, número, etc)</li>
        <li><strong>Marque Obrigatórios:</strong> Identifique campos obrigatórios para melhor UX</li>
        <li><strong>Adicione Ajuda:</strong> Use textos de ajuda para guiar o preenchimento</li>
    </ul>
</div>

<script src="js/form-builder.js"></script>

<?php include __DIR__ . '/../footer.php'; ?>