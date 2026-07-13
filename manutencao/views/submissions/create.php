<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>📝 Preencher Formulário</h1>
</div>

<?php if (!empty($error)): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- ETAPA 1: Selecionar Formulário -->
<?php if ($form_id <= 0 || !$selected_form): ?>
    <div style="max-width: 600px; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h2 style="margin-top: 0;">🔍 Selecione o Formulário</h2>
        
        <form method="GET" style="display: flex; flex-direction: column; gap: 1rem;">
            <input type="hidden" name="page" value="submissions">
            <input type="hidden" name="action" value="create">
            
            <div>
                <label for="form_id"><strong>Formulário *</strong></label>
                <select id="form_id" name="form_id" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                    <option value="">-- Selecione um Formulário --</option>
                    <?php if (!empty($forms)): ?>
                        <?php foreach ($forms as $form): ?>
                            <option value="<?php echo $form['id']; ?>">
                                📋 <?php echo htmlspecialchars($form['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn" style="flex: 1; background: #667eea; color: white; padding: 0.75rem; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">▶️ Continuar</button>
                <a href="?page=submissions" class="btn" style="flex: 1; background: #95a5a6; color: white; text-decoration: none; padding: 0.75rem; text-align: center; border-radius: 4px; font-weight: bold;">❌ Cancelar</a>
            </div>
        </form>
    </div>

<!-- ETAPA 2: Preencher Formulário -->
<?php else: ?>
    <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h2 style="margin-top: 0; color: #667eea;">📋 <?php echo htmlspecialchars($selected_form['name']); ?></h2>
        <p style="color: #7f8c8d; margin: 0 0 2rem 0;"><?php echo htmlspecialchars($selected_form['description'] ?? ''); ?></p>

        <form method="POST" style="display: flex; flex-direction: column; gap: 2rem;">
            <input type="hidden" name="submit_form" value="1">

            <!-- DISPOSITIVO E TÉCNICO (GLPI) -->
            <div style="background: #f0f7ff; padding: 2rem; border-radius: 8px; border-left: 4px solid #17a2b8;">
                <h3 style="margin-top: 0; color: #333;">🖥️ Dispositivo e Técnico</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div style="padding: 1rem; background: white; border-radius: 4px; border-left: 4px solid #17a2b8;">
                        <label style="display: block; margin-bottom: 0.75rem; font-weight: 500;">Dispositivo ou Grupo <span style="color: #e74c3c;">*</span></label>
                        <select name="device_target" id="device_target" required onchange="toggleGroupIssues()" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                            <option value="">-- Selecione --</option>
                            <?php if (!empty($devices)): ?>
                                <optgroup label="Dispositivos">
                                    <?php foreach ($devices as $device): ?>
                                        <option value="device_<?php echo $device['id']; ?>">
                                            <?php echo htmlspecialchars($device['name']); ?><?php echo !empty($device['sector_name']) ? ' (' . htmlspecialchars($device['sector_name']) . ')' : ''; ?>
                                            <?php echo ($device['source'] ?? 'manual') === 'glpi' ? ' [GLPI]' : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($device_groups)): ?>
                                <optgroup label="Grupos de Dispositivos">
                                    <?php foreach ($device_groups as $group): ?>
                                        <option value="group_<?php echo $group['id']; ?>">
                                            🗂️ <?php echo htmlspecialchars($group['name']); ?> (<?php echo intval($group['device_count']); ?> dispositivos)
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div style="padding: 1rem; background: white; border-radius: 4px; border-left: 4px solid #17a2b8;">
                        <label style="display: block; margin-bottom: 0.75rem; font-weight: 500;">Técnico Responsável <span style="color: #e74c3c;">*</span></label>
                        <select name="technician_id" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                            <option value="">-- Selecione um Técnico --</option>
                            <?php foreach ($technicians as $technician): ?>
                                <option value="<?php echo $technician['id']; ?>" <?php echo (int)$technician['id'] === (int)$current_user_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($technician['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($technicians)): ?>
                            <p style="color: #7f8c8d; font-size: 0.85rem; margin: 0.5rem 0 0 0;">
                                💡 Nenhum técnico cadastrado. Sincronize com o GLPI na tela de Usuários.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($device_groups)): ?>
                    <?php foreach ($device_groups as $group): ?>
                        <div id="group_issues_<?php echo $group['id']; ?>" class="group-issues-block" style="display: none; margin-top: 1.5rem; padding: 1rem; background: white; border-radius: 4px; border-left: 4px solid #f39c12;">
                            <p style="margin-top: 0;">⚠️ Algum dispositivo deste grupo apresenta problema? Marque e descreva:</p>
                            <?php foreach (($group_members[$group['id']] ?? []) as $member): ?>
                                <div style="display: flex; gap: 0.75rem; align-items: flex-start; margin-bottom: 0.75rem;">
                                    <input type="checkbox" name="issue_device[]" value="<?php echo $member['id']; ?>" onchange="toggleIssueText(this)" style="margin-top: 0.5rem;">
                                    <div style="flex: 1;">
                                        <span><?php echo htmlspecialchars($member['name']); ?><?php echo !empty($member['sector_name']) ? ' (' . htmlspecialchars($member['sector_name']) . ')' : ''; ?></span>
                                        <select name="issue_field_<?php echo $member['id']; ?>" style="display: none; width: 100%; margin-top: 0.5rem; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-size: 0.9rem;">
                                            <option value="">-- Qual item do checklist? --</option>
                                            <?php foreach ($form_fields as $checklist_field): ?>
                                                <?php if ($checklist_field['field_type'] === 'checkbox'): ?>
                                                    <option value="<?php echo $checklist_field['id']; ?>"><?php echo htmlspecialchars($checklist_field['label']); ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <textarea name="issue_desc_<?php echo $member['id']; ?>" placeholder="Descreva o problema encontrado neste dispositivo..." style="display: none; width: 100%; margin-top: 0.5rem; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-family: inherit;" rows="2"></textarea>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($form_sections) && count($form_sections) > 0): ?>
                <!-- RENDERIZAR SEÇÕES -->
                <?php foreach ($form_sections as $section): ?>
                    <?php 
                        // Filtrar campos desta seção
                        $section_fields = array_filter($form_fields, function($f) use ($section) {
                            return intval($f['section_id']) === intval($section['id']);
                        });
                    ?>
                    
                    <?php if (!empty($section_fields)): ?>
                        <div style="background: #f0f7ff; padding: 2rem; border-radius: 8px; border-left: 4px solid #3498db;">
                            <?php if ($section['show_title']): ?>
                                <h3 style="margin-top: 0; color: #333;">📑 <?php echo htmlspecialchars($section['title']); ?></h3>
                            <?php endif; ?>
                            <?php if (!empty($section['description'])): ?>
                                <p style="color: #7f8c8d; margin: <?php echo $section['show_title'] ? '0 0 1.5rem 0' : '0 0 1.5rem 0'; ?>;">
                                    <?php echo htmlspecialchars($section['description']); ?>
                                </p>
                            <?php endif; ?>
                            
                            <!-- GRID COM COLUNAS DA SEÇÃO -->
                            <div style="display: grid; grid-template-columns: repeat(<?php echo intval($section['columns']); ?>, 1fr); gap: 1.5rem;">
                                <?php foreach ($section_fields as $field): ?>
                                    <div style="padding: 1rem; background: white; border-radius: 4px; border-left: 4px solid #667eea;">
                                        <label style="display: block; margin-bottom: 0.75rem; font-weight: 500;">
                                            <?php echo htmlspecialchars($field['label']); ?>
                                            <?php if ($field['is_required']): ?>
                                                <span style="color: #e74c3c;">*</span>
                                            <?php endif; ?>
                                        </label>

                                        <?php if (!empty($field['help_text'])): ?>
                                            <p style="color: #7f8c8d; font-size: 0.85rem; margin: 0 0 0.5rem 0;">
                                                💡 <?php echo htmlspecialchars($field['help_text']); ?>
                                            </p>
                                        <?php endif; ?>

                                        <!-- CAMPO DE TEXTO -->
                                        <?php if ($field['field_type'] === 'text'): ?>
                                            <input type="text" 
                                                   name="field_<?php echo $field['id']; ?>"
                                                   placeholder="<?php echo htmlspecialchars($field['placeholder']); ?>"
                                                   <?php if ($field['is_required']): ?>required<?php endif; ?>
                                                   style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">

                                        <!-- EMAIL -->
                                        <?php elseif ($field['field_type'] === 'email'): ?>
                                            <input type="email" 
                                                   name="field_<?php echo $field['id']; ?>"
                                                   placeholder="<?php echo htmlspecialchars($field['placeholder']); ?>"
                                                   <?php if ($field['is_required']): ?>required<?php endif; ?>
                                                   style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">

                                        <!-- NÚMERO -->
                                        <?php elseif ($field['field_type'] === 'number'): ?>
                                            <input type="number" 
                                                   name="field_<?php echo $field['id']; ?>"
                                                   placeholder="<?php echo htmlspecialchars($field['placeholder']); ?>"
                                                   <?php if ($field['is_required']): ?>required<?php endif; ?>
                                                   style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">

                                        <!-- DATA -->
                                        <?php elseif ($field['field_type'] === 'date'): ?>
                                            <input type="date"
                                                   name="field_<?php echo $field['id']; ?>"
                                                   value="<?php echo date('Y-m-d'); ?>"
                                                   <?php if ($field['is_required']): ?>required<?php endif; ?>
                                                   style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">

                                        <!-- HORA -->
                                        <?php elseif ($field['field_type'] === 'time'): ?>
                                            <input type="time" 
                                                   name="field_<?php echo $field['id']; ?>"
                                                   <?php if ($field['is_required']): ?>required<?php endif; ?>
                                                   style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">

                                        <!-- TEXTAREA -->
                                        <?php elseif ($field['field_type'] === 'textarea'): ?>
                                            <textarea name="field_<?php echo $field['id']; ?>"
                                                      placeholder="<?php echo htmlspecialchars($field['placeholder']); ?>"
                                                      rows="4"
                                                      <?php if ($field['is_required']): ?>required<?php endif; ?>
                                                      style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; font-family: inherit;"></textarea>

                                        <!-- SELECT -->
                                        <?php elseif ($field['field_type'] === 'select'): ?>
                                            <select name="field_<?php echo $field['id']; ?>"
                                                    <?php if ($field['is_required']): ?>required<?php endif; ?>
                                                    style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                                                <option value="">-- Selecione --</option>
                                                <option value="active">Ativo</option>
                                                <option value="inactive">Inativo</option>
                                            </select>

                                        <!-- RADIO -->
                                        <?php elseif ($field['field_type'] === 'radio'): ?>
                                            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                                                <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                                                    <input type="radio" name="field_<?php echo $field['id']; ?>" value="Preventiva" <?php if ($field['is_required']): ?>required<?php endif; ?>>
                                                    Preventiva
                                                </label>
                                                <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                                                    <input type="radio" name="field_<?php echo $field['id']; ?>" value="Corretiva" <?php if ($field['is_required']): ?>required<?php endif; ?>>
                                                    Corretiva
                                                </label>
                                            </div>

                                        <!-- CHECKBOX -->
                                        <?php elseif ($field['field_type'] === 'checkbox'): ?>
                                            <label style="display: flex; align-items: center; gap: 0.75rem; font-weight: normal;">
                                                <input type="checkbox" name="field_<?php echo $field['id']; ?>" value="<?php echo htmlspecialchars($field['label']); ?>" style="width: 18px; height: 18px; cursor: pointer;">
                                                <span><?php echo htmlspecialchars($field['label']); ?></span>
                                            </label>

                                        <!-- FILE -->
                                        <?php elseif ($field['field_type'] === 'file'): ?>
                                            <input type="file" 
                                                   name="field_<?php echo $field['id']; ?>"
                                                   <?php if ($field['is_required']): ?>required<?php endif; ?>
                                                   style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">

                                        <!-- OUTROS TIPOS -->
                                        <?php else: ?>
                                            <input type="<?php echo htmlspecialchars($field['field_type']); ?>"
                                                   name="field_<?php echo $field['id']; ?>"
                                                   placeholder="<?php echo htmlspecialchars($field['placeholder']); ?>"
                                                   <?php if ($field['is_required']): ?>required<?php endif; ?>
                                                   style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- CAMPOS SEM SEÇÃO -->
            <?php 
                $fields_without_section = array_filter($form_fields, function($f) {
                    return empty($f['section_id']) || intval($f['section_id']) === 0;
                });
            ?>
            
            <?php if (!empty($fields_without_section)): ?>
                <div style="background: #fff8e1; padding: 2rem; border-radius: 8px; border-left: 4px solid #f39c12;">
                    <h3 style="margin-top: 0; color: #333;">⚠️ Campos Adicionais</h3>
                    
                    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                        <?php foreach ($fields_without_section as $field): ?>
                            <div style="padding: 1rem; background: white; border-radius: 4px; border-left: 4px solid #f39c12;">
                                <label style="display: block; margin-bottom: 0.75rem; font-weight: 500;">
                                    <?php echo htmlspecialchars($field['label']); ?>
                                    <?php if ($field['is_required']): ?>
                                        <span style="color: #e74c3c;">*</span>
                                    <?php endif; ?>
                                </label>

                                <?php if (!empty($field['help_text'])): ?>
                                    <p style="color: #7f8c8d; font-size: 0.85rem; margin: 0 0 0.5rem 0;">
                                        💡 <?php echo htmlspecialchars($field['help_text']); ?>
                                    </p>
                                <?php endif; ?>

                                <!-- MESMO CÓDIGO DE CAMPOS ACIMA -->
                                <?php if ($field['field_type'] === 'text'): ?>
                                    <input type="text" 
                                           name="field_<?php echo $field['id']; ?>"
                                           placeholder="<?php echo htmlspecialchars($field['placeholder']); ?>"
                                           <?php if ($field['is_required']): ?>required<?php endif; ?>
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                                <?php elseif ($field['field_type'] === 'email'): ?>
                                    <input type="email" 
                                           name="field_<?php echo $field['id']; ?>"
                                           placeholder="<?php echo htmlspecialchars($field['placeholder']); ?>"
                                           <?php if ($field['is_required']): ?>required<?php endif; ?>
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                                <?php elseif ($field['field_type'] === 'number'): ?>
                                    <input type="number" 
                                           name="field_<?php echo $field['id']; ?>"
                                           placeholder="<?php echo htmlspecialchars($field['placeholder']); ?>"
                                           <?php if ($field['is_required']): ?>required<?php endif; ?>
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                                <?php elseif ($field['field_type'] === 'date'): ?>
                                    <input type="date"
                                           name="field_<?php echo $field['id']; ?>"
                                           value="<?php echo date('Y-m-d'); ?>"
                                           <?php if ($field['is_required']): ?>required<?php endif; ?>
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                                <?php elseif ($field['field_type'] === 'time'): ?>
                                    <input type="time" 
                                           name="field_<?php echo $field['id']; ?>"
                                           <?php if ($field['is_required']): ?>required<?php endif; ?>
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                                <?php elseif ($field['field_type'] === 'textarea'): ?>
                                    <textarea name="field_<?php echo $field['id']; ?>"
                                              placeholder="<?php echo htmlspecialchars($field['placeholder']); ?>"
                                              rows="4"
                                              <?php if ($field['is_required']): ?>required<?php endif; ?>
                                              style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; font-family: inherit;"></textarea>
                                <?php elseif ($field['field_type'] === 'select'): ?>
                                    <select name="field_<?php echo $field['id']; ?>"
                                            <?php if ($field['is_required']): ?>required<?php endif; ?>
                                            style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                                        <option value="">-- Selecione --</option>
                                        <option value="active">Ativo</option>
                                        <option value="inactive">Inativo</option>
                                    </select>
                                <?php elseif ($field['field_type'] === 'radio'): ?>
                                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                                            <input type="radio" name="field_<?php echo $field['id']; ?>" value="Preventiva" <?php if ($field['is_required']): ?>required<?php endif; ?>>
                                            Preventiva
                                        </label>
                                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal;">
                                            <input type="radio" name="field_<?php echo $field['id']; ?>" value="Corretiva" <?php if ($field['is_required']): ?>required<?php endif; ?>>
                                            Corretiva
                                        </label>
                                    </div>
                                <?php elseif ($field['field_type'] === 'checkbox'): ?>
                                    <label style="display: flex; align-items: center; gap: 0.75rem; font-weight: normal;">
                                        <input type="checkbox" name="field_<?php echo $field['id']; ?>" value="<?php echo htmlspecialchars($field['label']); ?>" style="width: 18px; height: 18px; cursor: pointer;">
                                        <span><?php echo htmlspecialchars($field['label']); ?></span>
                                    </label>
                                <?php elseif ($field['field_type'] === 'file'): ?>
                                    <input type="file" 
                                           name="field_<?php echo $field['id']; ?>"
                                           <?php if ($field['is_required']): ?>required<?php endif; ?>
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                                <?php else: ?>
                                    <input type="<?php echo htmlspecialchars($field['field_type']); ?>"
                                           name="field_<?php echo $field['id']; ?>"
                                           placeholder="<?php echo htmlspecialchars($field['placeholder']); ?>"
                                           <?php if ($field['is_required']): ?>required<?php endif; ?>
                                           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($form_fields)): ?>
                <div style="background: #fff3cd; padding: 1.5rem; border-radius: 4px; text-align: center;">
                    <p style="color: #7d5c0d; margin: 0;">⚠️ Este formulário não possui campos</p>
                </div>
            <?php endif; ?>

            <!-- BOTÕES -->
            <div style="display: flex; gap: 1rem; margin-top: 2rem; padding-top: 2rem; border-top: 2px solid #ddd;">
                <button type="submit" class="btn" style="flex: 1; background: #27ae60; color: white; padding: 0.75rem; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; font-weight: bold;">✅ Enviar Preenchimento</button>
                <a href="?page=submissions&action=create" class="btn" style="flex: 1; background: #95a5a6; color: white; text-decoration: none; padding: 0.75rem; text-align: center; border-radius: 4px; font-weight: bold;">⬅️ Voltar</a>
            </div>
        </form>
    </div>

    <script>
        function toggleGroupIssues() {
            document.querySelectorAll('.group-issues-block').forEach(function (el) {
                el.style.display = 'none';
            });

            const val = document.getElementById('device_target').value;
            if (val.indexOf('group_') === 0) {
                const id = val.split('_')[1];
                const block = document.getElementById('group_issues_' + id);
                if (block) {
                    block.style.display = 'block';
                }
            }
        }

        function toggleIssueText(checkbox) {
            const container = checkbox.parentElement;
            const textarea = container.querySelector('textarea');
            const select = container.querySelector('select');
            if (textarea) {
                textarea.style.display = checkbox.checked ? 'block' : 'none';
            }
            if (select) {
                select.style.display = checkbox.checked ? 'block' : 'none';
            }
        }
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>