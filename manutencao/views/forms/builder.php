<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <div>
        <h1>🔧 Builder - <?php echo htmlspecialchars($form['name']); ?></h1>
        <p>Crie seções, organize campos e configure múltiplas colunas</p>
    </div>
</div>

<div style="background: white; padding: 2rem; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
    <!-- BOTÕES DE AÇÃO -->
    <div style="display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap;">
        <button id="btn-add-section" class="btn" style="background: #3498db; color: white;">📑 Adicionar Seção</button>
        <button id="btn-add-field" class="btn" style="background: #667eea; color: white;">➕ Adicionar Campo</button>
        <a href="?page=forms&action=edit&id=<?php echo $form['id']; ?>" class="btn" style="background: #f39c12; color: white; text-decoration: none;">✏️ Editar Formulário</a>
        <a href="?page=forms" class="btn" style="background: #95a5a6; color: white; text-decoration: none;">⬅️ Voltar</a>
    </div>

    <!-- ESTRUTURA DO FORMULÁRIO -->
    <div id="fieldsContainer">
        <p style="color: #7f8c8d; text-align: center;">⏳ Carregando estrutura do formulário...</p>
    </div>
</div>

<!-- DICAS -->
<div style="margin-top: 2rem; background: #e8f8f5; padding: 1.5rem; border-radius: 4px; border-left: 4px solid #27ae60;">
    <h3 style="margin-top: 0; color: #27ae60;">💡 Como Usar o Builder</h3>
    <ul style="margin: 1rem 0; padding-left: 1.5rem; color: #27ae60;">
        <li><strong>Seções:</strong> Use para agrupar campos relacionados (ex: "Dados Pessoais", "Informações Técnicas")</li>
        <li><strong>Colunas:</strong> Divida uma seção em 1, 2, 3 ou até 4 colunas para economizar espaço</li>
        <li><strong>Campos:</strong> Adicione dentro de seções ou isolados - escolha o tipo mais adequado</li>
        <li><strong>Organização:</strong> Reorganize clicando nos botões de editar e deletar</li>
        <li><strong>Dica Pro:</strong> Use 2-4 colunas para manter o formulário compacto e profissional</li>
    </ul>
</div>

<!-- SCRIPT DO NOVO BUILDER -->
<script src="js/form-builder-advanced.js"></script>

<?php include __DIR__ . '/../footer.php'; ?>