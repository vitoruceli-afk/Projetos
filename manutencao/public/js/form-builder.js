// Form Builder - Sistema de Manutenção

class FormBuilder {
    constructor(formId) {
        this.formId = formId;
        this.fields = [];
        this.sections = [];
        this.initEvents();
        this.loadFields();
    }

    initEvents() {
        // Botão Adicionar Campo
        const btnAdd = document.getElementById('btn-add-field');
        if (btnAdd) {
            btnAdd.addEventListener('click', () => this.showAddFieldModal());
        }

        // Botão Adicionar Seção
        const btnSection = document.getElementById('btn-add-section');
        if (btnSection) {
            btnSection.addEventListener('click', () => this.showAddSectionModal());
        }
    }

    showAddFieldModal() {
        const modal = `
            <div id="fieldModal" style="display: block; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 8px; width: 90%; max-width: 600px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-height: 90vh; overflow-y: auto;">
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
                        <button onclick="formBuilder.saveField()" class="btn" style="flex: 1; background: #667eea;">✅ Adicionar</button>
                        <button onclick="document.getElementById('fieldModal').remove()" class="btn" style="background: #95a5a6; flex: 1;">❌ Cancelar</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modal);
    }

    showAddSectionModal() {
        const modal = `
            <div id="sectionModal" style="display: block; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 8px; width: 90%; max-width: 600px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <h2>📑 Adicionar Seção</h2>
                    
                    <div class="form-group">
                        <label>Título da Seção *</label>
                        <input type="text" id="sectionTitle" placeholder="Ex: Informações Básicas" required>
                    </div>

                    <div class="form-group">
                        <label>Descrição</label>
                        <textarea id="sectionDescription" placeholder="Descrição da seção" rows="3"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Número de Colunas *</label>
                        <select id="sectionColumns" required>
                            <option value="1">1 Coluna</option>
                            <option value="2">2 Colunas</option>
                            <option value="3">3 Colunas</option>
                        </select>
                    </div>

                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button onclick="formBuilder.saveSection()" class="btn" style="flex: 1; background: #667eea;">✅ Adicionar</button>
                        <button onclick="document.getElementById('sectionModal').remove()" class="btn" style="background: #95a5a6; flex: 1;">❌ Cancelar</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modal);
    }

    saveField() {
        const label = document.getElementById('fieldLabel').value.trim();
        const type = document.getElementById('fieldType').value;
        const placeholder = document.getElementById('fieldPlaceholder').value.trim();
        const helpText = document.getElementById('fieldHelpText').value.trim();
        const required = document.getElementById('fieldRequired').checked;

        if (!label || !type) {
            alert('Preencha os campos obrigatórios!');
            return;
        }

        // Salvar no servidor usando api.php
        this.sendRequest('api.php?action=addField', {
            form_id: this.formId,
            label: label,
            field_type: type,
            placeholder: placeholder,
            help_text: helpText,
            is_required: required ? 1 : 0
        }, () => {
            document.getElementById('fieldModal').remove();
            this.loadFields();
            alert('✅ Campo adicionado com sucesso!');
        });
    }

    saveSection() {
        const title = document.getElementById('sectionTitle').value.trim();
        const description = document.getElementById('sectionDescription').value.trim();
        const columns = document.getElementById('sectionColumns').value;

        if (!title) {
            alert('Título da seção é obrigatório!');
            return;
        }

        this.sendRequest('api.php?action=addSection', {
            form_id: this.formId,
            title: title,
            description: description,
            columns: columns
        }, () => {
            document.getElementById('sectionModal').remove();
            this.loadFields();
            alert('✅ Seção adicionada com sucesso!');
        });
    }

    loadFields() {
        // Buscar campos do servidor
        fetch(`api.php?action=getFields&form_id=${this.formId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.renderFields(data);
                } else {
                    console.error('Erro:', data.message);
                }
            })
            .catch(error => {
                console.error('Erro ao carregar campos:', error);
            });
    }

    renderFields(data) {
        const container = document.getElementById('fieldsContainer');
        if (!container) return;

        container.innerHTML = '';

        if (data.fields && data.fields.length > 0) {
            data.fields.forEach(field => {
                const fieldHTML = `
                    <div style="background: white; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; border-left: 4px solid #667eea; display: flex; justify-content: space-between; align-items: center;">
                        <div style="flex: 1;">
                            <strong>${field.label}</strong><br>
                            <small style="color: #7f8c8d;">Tipo: ${field.field_type} ${field.is_required ? '(Obrigatório)' : ''}</small>
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            <button onclick="formBuilder.deleteField(${field.id})" class="btn" style="padding: 0.5rem 1rem; font-size: 0.9rem; background: #e74c3c; color: white;">🗑️</button>
                        </div>
                    </div>
                `;
                container.insertAdjacentHTML('beforeend', fieldHTML);
            });
        } else {
            container.innerHTML = '<p style="color: #7f8c8d; text-align: center;">Nenhum campo adicionado ainda. Clique em "➕ Adicionar Campo" para começar.</p>';
        }
    }

    deleteField(fieldId) {
        if (!confirm('Deseja deletar este campo?')) return;

        this.sendRequest('api.php?action=deleteField', {
            field_id: fieldId
        }, () => {
            this.loadFields();
            alert('✅ Campo deletado com sucesso!');
        });
    }

    sendRequest(url, data, callback) {
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(result => {
            if (result.success) {
                callback();
            } else {
                alert('❌ Erro: ' + (result.message || 'Erro desconhecido'));
                console.error('Erro do servidor:', result);
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('❌ Erro ao salvar: ' + error.message);
        });
    }
}

// Inicializar ao carregar a página
let formBuilder;
document.addEventListener('DOMContentLoaded', () => {
    const formId = new URLSearchParams(window.location.search).get('id');
    if (formId) {
        formBuilder = new FormBuilder(formId);
    }
});