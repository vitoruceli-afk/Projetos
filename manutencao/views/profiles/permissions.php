<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>🔐 Permissões - <?php echo htmlspecialchars($profile['name']); ?></h1>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<form method="POST" style="max-width: 1000px;">
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>Módulo</th>
                    <th>Visualizar</th>
                    <th>Criar</th>
                    <th>Editar</th>
                    <th>Deletar</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $modules = ['users' => 'Usuários', 'profiles' => 'Perfis', 'forms' => 'Formulários', 'submissions' => 'Preenchimentos', 'sectors' => 'Setores', 'smtp' => 'SMTP', 'reports' => 'Relatórios'];
                
                foreach ($modules as $module_key => $module_name): 
                    $perm = array_filter($permissions, function($p) use ($module_key) {
                        return $p['module_name'] === $module_key;
                    });
                    $perm = array_shift($perm);
                ?>
                    <tr>
                        <td><strong><?php echo $module_name; ?></strong></td>
                        <td style="text-align: center;">
                            <input type="checkbox" name="<?php echo $module_key; ?>_view" 
                                   <?php echo ($perm && $perm['can_view']) ? 'checked' : ''; ?>>
                        </td>
                        <td style="text-align: center;">
                            <input type="checkbox" name="<?php echo $module_key; ?>_create" 
                                   <?php echo ($perm && $perm['can_create']) ? 'checked' : ''; ?>>
                        </td>
                        <td style="text-align: center;">
                            <input type="checkbox" name="<?php echo $module_key; ?>_edit" 
                                   <?php echo ($perm && $perm['can_edit']) ? 'checked' : ''; ?>>
                        </td>
                        <td style="text-align: center;">
                            <input type="checkbox" name="<?php echo $module_key; ?>_delete" 
                                   <?php echo ($perm && $perm['can_delete']) ? 'checked' : ''; ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
        <button type="submit" class="btn">✅ Salvar Permissões</button>
        <a href="?page=profiles" class="btn" style="background: #95a5a6; text-decoration: none;">❌ Cancelar</a>
    </div>
</form>

<?php include __DIR__ . '/../footer.php'; ?>