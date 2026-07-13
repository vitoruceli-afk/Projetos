// Form Builder Melhorado - Sistema de Manutenção
// Com suporte completo a seções, colunas e drag-and-drop

class FormBuilderAdvanced {
    constructor(formId) {
        this.formId = formId;
        this.fields = [];
        this.sections = [];
        this.draggedElement = null;
        this.initEvents();
        this.loadData();
    }

    initEvents() {
        // Botão Adicionar Campo
        const btnAddField = document.getElementById('btn-add-field');
        if (btnAddField) {
            btnAddField.addEventListener('click', () => this.showAddFieldModal());
        }

        // Botão Adicionar Seção
        const btnAddSection = document.getElementById('btn-add-section');
        if (btnAddSection) {
            btnAddSection.addEventListener('click', () => this.showAddSectionModal());
        }
    }

    // ==================== MODAIS ====================

    showAddSectionModal() {
        const modal = `
            <div id="sectionModal" class="builder-modal" style="display: block; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
                <div class="modal-content" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 8px; width: 90%; max-width: 600px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <h2>📑 Adicionar Seção</h2>
                    
                    <div class="form-group">
                        <label>Título da Seção *</label>
                        <input type="text" id="sectionTitle" placeholder="Ex: Informações Básicas" required>
                    </div>

                    <div class="form-group">
                        <label>Descrição (Opcional)</label>
                        <textarea id="sectionDescription" placeholder="Descrição da seção" rows="2"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Número de Colunas *</label>
                        <select id="sectionColumns" required>
                            <option value="1">1 Coluna (Largura Completa)</option>
                            <option value="2">2 Colunas (50% cada)</option>
                            <option value="3">3 Colunas (33% cada)</option>
                            <option value="4">4 Colunas (25% cada)</option>
                        </select>
                    </div>

                    <div style="background: #f0f7ff; padding: 1rem; border-radius: 4px; margin: 1rem 0; font-size: 0.9rem; color: #0c5460;">
                        <strong>💡 Dica:</strong> Use mais colunas para economizar espaço e organizar campos relacionados lado a lado.
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button onclick="formBuilder.saveSection()" class="btn" style="flex: 1; background: #667eea; color: white;">✅ Criar Seção</button>
                        <button onclick="document.getElementById('sectionModal').remove()" class="btn" style="background: #95a5a6; flex: 1; color: white;">❌ Cancelar</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modal);
        document.getElementById('sectionTitle').focus();
    }

    showAddFieldModal() {
        const sectionOptions = this.sections.map(s => 
            `<option value="${s.id}">${s.title}</option>`
        ).join('');

        const modal = `
            <div id="fieldModal" class="builder-modal" style="display: block; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
                <div class="modal-content" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 8px; width: 90%; max-width: 600px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-height: 90vh; overflow-y: auto;">
                    <h2>➕ Adicionar Campo</h2>
                    
                    <div class="form-group">
                        <label>Rótulo (Label) *</label>
                        <input type="text" id="fieldLabel" placeholder="Ex: Nome do Cliente" required>
                    </div>

                    <div class="form-group">
                        <label>Tipo de Campo *</label>
                        <select id="fieldType" required>
                            <optgroup label="📝 Texto">
                                <option value="text">Texto</option>
                                <option value="email">Email</option>
                                <option value="password">Senha</option>
                                <option value="tel">Telefone</option>
                                <option value="url">URL</option>
                            </optgroup>
                            <optgroup label="🔢 Números">
                                <option value="number">Número</option>
                            </optgroup>
                            <optgroup label="✓ Seleções">
                                <option value="checkbox">Caixa de Seleção</option>
                                <option value="radio">Botão de Opção</option>
                                <option value="select">Menu Suspenso</option>
                            </optgroup>
                            <optgroup label="📅 Datas e Horas">
                                <option value="date">Data</option>
                                <option value="datetime-local">Data e Hora</option>
                                <option value="time">Hora</option>
                            </optgroup>
                            <optgroup label="📄 Texto Longo">
                                <option value="textarea">Área de Texto</option>
                                <option value="file">Upload de Arquivo</option>
                            </optgroup>
                        </select>
                    </div>

                    ${this.sections.length > 0 ? `
                        <div class="form-group">
                            <label>Seção (Opcional)</label>
                            <select id="fieldSection">
                                <option value="0">Sem Seção (Adicionado ao Topo)</option>
                                ${sectionOptions}
                            </select>
                        </div>
                    ` : ''}

                    <div class="form-group">
                        <label>Placeholder</label>
                        <input type="text" id="fieldPlaceholder" placeholder="Ex: Digite aqui...">
                    </div>

                    <div class="form-group">
                        <label>Texto de Ajuda</label>
                        <input type="text" id="fieldHelpText" placeholder="Ex: Campo obrigatório">
                    </div>

                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="fieldRequired">
                            Campo Obrigatório
                        </label>
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button onclick="formBuilder.saveField()" class="btn" style="flex: 1; background: #667eea; color: white;">✅ Adicionar</button>
                        <button onclick="document.getElementById('fieldModal').remove()" class="btn" style="background: #95a5a6; flex: 1; color: white;">❌ Cancelar</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modal);
        document.getElementById('fieldLabel').focus();
    }

    showEditSectionModal(sectionId) {
        const section = this.sections.find(s => s.id === sectionId);
        if (!section) return;

        const modal = `
            <div id="editSectionModal" class="builder-modal" style="display: block; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
                <div class="modal-content" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 8px; width: 90%; max-width: 600px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <h2>✏️ Editar Seção</h2>
                    
                    <div class="form-group">
                        <label>Título da Seção *</label>
                        <input type="text" id="editSectionTitle" value="${section.title}" required>
                    </div>

                    <div class="form-group">
                        <label>Descrição</label>
                        <textarea id="editSectionDescription" rows="2">${section.description || ''}</textarea>
                    </div>

                    <div class="form-group">
                        <label>Número de Colunas *</label>
                        <select id="editSectionColumns" required>
                            <option value="1" ${section.columns == 1 ? 'selected' : ''}>1 Coluna</option>
                            <option value="2" ${section.columns == 2 ? 'selected' : ''}>2 Colunas</option>
                            <option value="3" ${section.columns == 3 ? 'selected' : ''}>3 Colunas</option>
                            <option value="4" ${section.columns == 4 ? 'selected' : ''}>4 Colunas</option>
                        </select>
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button onclick="formBuilder.updateSection(${sectionId})" class="btn" style="flex: 1; background: #667eea; color: white;">💾 Salvar Alterações</button>
                        <button onclick="formBuilder.deleteSection(${sectionId})" class="btn" style="flex: 1; background: #e74c3c; color: white;">🗑️ Deletar Seção</button>
                        <button onclick="document.getElementById('editSectionModal').remove()" class="btn" style="background: #95a5a6; flex: 1; color: white;">❌ Cancelar</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modal);
    }

    // ==================== SALVAR ====================

    saveSection() {
        const title = document.getElementById('sectionTitle').value.trim();
        const description = document.getElementById('sectionDescription').value.trim();
        const columns = document.getElementById('sectionColumns').value;

        if (!title) {
            alert('❌ Título da seção é obrigatório!');
            return;
        }

        this.sendRequest('api.php?action=addSection', {
            form_id: this.formId,
            title: title,
            description: description,
            columns: parseInt(columns)
        }, () => {
            document.getElementById('sectionModal')?.remove();
            this.loadData();
            alert('✅ Seção criada com sucesso!');
        });
    }

    saveField() {
        const label = document.getElementById('fieldLabel').value.trim();
        const type = document.getElementById('fieldType').value;
        const sectionId = document.getElementById('fieldSection')?.value || 0;
        const placeholder = document.getElementById('fieldPlaceholder').value.trim();
        const helpText = document.getElementById('fieldHelpText').value.trim();
        const required = document.getElementById('fieldRequired').checked;

        if (!label || !type) {
            alert('❌ Preencha os campos obrigatórios!');
            return;
        }

        this.sendRequest('api.php?action=addField', {
            form_id: this.formId,
            label: label,
            field_type: type,
            section_id: parseInt(sectionId),
            placeholder: placeholder,
            help_text: helpText,
            is_required: required ? 1 : 0
        }, () => {
            document.getElementById('fieldModal')?.remove();
            this.loadData();
            alert('✅ Campo adicionado com sucesso!');
        });
    }

    updateSection(sectionId) {
        const title = document.getElementById('editSectionTitle').value.trim();
        const description = document.getElementById('editSectionDescription').value.trim();
        const columns = document.getElementById('editSectionColumns').value;

        if (!title) {
            alert('❌ Título é obrigatório!');
            return;
        }

        this.sendRequest('api.php?action=updateSection', {
            section_id: sectionId,
            title: title,
            description: description,
            columns: parseInt(columns)
        }, () => {
            document.getElementById('editSectionModal')?.remove();
            this.loadData();
            alert('✅ Seção atualizada com sucesso!');
        });
    }

    deleteSection(sectionId) {
        if (!confirm('⚠️ Tem certeza? Todos os campos dentro desta seção também serão deletados!')) {
            return;
        }

        this.sendRequest('api.php?action=deleteSection', {
            section_id: sectionId
        }, () => {
            document.getElementById('editSectionModal')?.remove();
            this.loadData();
            alert('✅ Seção deletada com sucesso!');
        });
    }

    deleteField(fieldId) {
        if (!confirm('❌ Deseja deletar este campo?')) return;

        this.sendRequest('api.php?action=deleteField', {
            field_id: fieldId
        }, () => {
            this.loadData();
            alert('✅ Campo deletado!');
        });
    }

    // ==================== CARREGAMENTO ====================

    loadData() {
        // Buscar seções e campos
        fetch(`api.php?action=getFormData&form_id=${this.formId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.sections = data.sections || [];
                    this.fields = data.fields || [];
                    this.render();
                } else {
                    console.error('Erro:', data.message);
                }
            })
            .catch(error => console.error('Erro ao carregar:', error));
    }

    // ==================== RENDERIZAÇÃO ====================

    render() {
        const container = document.getElementById('fieldsContainer');
        if (!container) return;

        let html = '';

        if (this.sections.length === 0 && this.fields.length === 0) {
            html = `
                <div style="text-align: center; padding: 2rem; background: #f8f9fa; border-radius: 4px;">
                    <p style="color: #7f8c8d; margin: 0;">📭 Nenhuma seção ou campo adicionado ainda.</p>
                    <p style="color: #7f8c8d; margin: 0.5rem 0 0 0;">Clique em "Adicionar Seção" ou "Adicionar Campo" para começar.</p>
                </div>
            `;
        } else {
            // Renderizar seções
            this.sections.forEach((section, index) => {
                const sectionFields = this.fields.filter(f => f.section_id == section.id);
                const colWidth = (100 / section.columns);

                html += `
                    <div class="section-item" draggable="true" data-section-id="${section.id}" style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; border: 2px solid #3498db; cursor: move;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 0.5rem 0; color: #333;">📑 ${section.title}</h3>
                                ${section.description ? `<p style="margin: 0; color: #7f8c8d; font-size: 0.9rem;">${section.description}</p>` : ''}
                                <p style="margin: 0.5rem 0 0 0; font-size: 0.85rem; color: #3498db;"><strong>📊 ${section.columns} coluna${section.columns > 1 ? 's' : ''}</strong></p>
                            </div>
                            <div style="display: flex; gap: 0.5rem;">
                                <button onclick="formBuilder.showEditSectionModal(${section.id})" class="btn" style="padding: 0.5rem 1rem; background: #f39c12; color: white; border: none; border-radius: 4px; cursor: pointer;">✏️</button>
                                <button onclick="formBuilder.deleteSection(${section.id})" class="btn" style="padding: 0.5rem 1rem; background: #e74c3c; color: white; border: none; border-radius: 4px; cursor: pointer;">🗑️</button>
                            </div>
                        </div>

                        ${sectionFields.length > 0 ? `
                            <div style="display: grid; grid-template-columns: repeat(${section.columns}, 1fr); gap: 1rem;">
                                ${sectionFields.map(field => `
                                    <div class="field-item" style="background: white; padding: 1rem; border-radius: 4px; border-left: 4px solid #667eea;">
                                        <strong>${field.label}</strong><br>
                                        <small style="color: #7f8c8d;">📌 ${field.field_type} ${field.is_required ? '(Obrigatório)' : ''}</small><br>
                                        <button onclick="formBuilder.deleteField(${field.id})" class="btn" style="margin-top: 0.5rem; padding: 0.3rem 0.8rem; font-size: 0.8rem; background: #e74c3c; color: white; border: none; border-radius: 3px; cursor: pointer;">🗑️ Remover</button>
                                    </div>
                                `).join('')}
                            </div>
                        ` : `
                            <div style="background: white; padding: 1rem; border-radius: 4px; text-align: center; color: #7f8c8d;">
                                Nenhum campo nesta seção. Use "Adicionar Campo" e selecione esta seção.
                            </div>
                        `}
                    </div>
                `;
            });

            // Campos sem seção
            const fieldsWithoutSection = this.fields.filter(f => !f.section_id || f.section_id == 0);
            if (fieldsWithoutSection.length > 0) {
                html += `
                    <div style="background: #fff8e1; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; border: 2px solid #f39c12;">
                        <h3 style="margin: 0 0 1rem 0; color: #333;">⚠️ Campos sem Seção</h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            ${fieldsWithoutSection.map(field => `
                                <div class="field-item" style="background: white; padding: 1rem; border-radius: 4px; border-left: 4px solid #f39c12;">
                                    <strong>${field.label}</strong><br>
                                    <small style="color: #7f8c8d;">📌 ${field.field_type} ${field.is_required ? '(Obrigatório)' : ''}</small><br>
                                    <button onclick="formBuilder.deleteField(${field.id})" class="btn" style="margin-top: 0.5rem; padding: 0.3rem 0.8rem; font-size: 0.8rem; background: #e74c3c; color: white; border: none; border-radius: 3px; cursor: pointer;">🗑️ Remover</button>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }
        }

        container.innerHTML = html;
    }

    // ==================== REQUISIÇÕES ====================

    sendRequest(url, data, callback) {
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                callback();
            } else {
                alert('❌ Erro: ' + (result.message || 'Desconhecido'));
            }
        })
        .catch(error => alert('❌ Erro: ' + error.message));
    }
}

// Inicializar
let formBuilder;
document.addEventListener('DOMContentLoaded', () => {
    const formId = new URLSearchParams(window.location.search).get('id');
    if (formId) {
        formBuilder = new FormBuilderAdvanced(formId);
    }
});
