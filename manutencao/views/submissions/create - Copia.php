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
            
            <?php if (!empty($form_fields)): ?>
                <?php foreach ($form_fields as $field): ?>
                    <div style="padding: 1.5rem; background: #f9f9f9; border-radius: 4px; border-left: 4px solid #667eea;">
                        <label style="display: block; margin-bottom: 0.75rem; font-weight: 500;">
                            <?php echo htmlspecialchars($field['label']); ?>
                            <?php if ($field['is_required']): ?>
                                <span style="color: #e74c3c;">*</span>
                            <?php endif; ?>
                        </label>

                        <?php if (!empty($field['help_text'])): ?>
                            <p style="color: #7f8c8d; font-size: 0.9rem; margin: 0 0 0.5rem 0;">
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
                            <div style="display: flex; gap: 2rem;">
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
                                <input type="checkbox" name="field_<?php echo $field['id']; ?>" value="<?php echo htmlspecialchars($field['label']); ?>" style="width: 20px; height: 20px; cursor: pointer;">
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
            <?php else: ?>
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
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>