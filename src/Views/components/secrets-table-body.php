<?php
/**
 * Secrets table body component
 * 
 * Required variables:
 * @var array $variables - Associative array of secret name => value
 * @var array $managed - Managed secrets configuration
 * @var string $serviceId - Current service ID
 * @var string $csrfToken - CSRF token
 */

if (empty($variables)):
?>
    <tr>
        <td colspan="4">
            <div class="empty-state">
                <i data-lucide="ghost" style="width:32px;height:32px;"></i>
                <p>No variables found in this scope.</p>
            </div>
        </td>
    </tr>
<?php
else:
    foreach ($variables as $name => $value):
        $config = $managed[($serviceId ?: 'global') . ':' . $name] ?? null;
        include __DIR__ . '/secret-row.php';
    endforeach;
endif;
?>
