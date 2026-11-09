<?php

if (!function_exists('get_current_tenant')) {
    function get_current_tenant(PDO $pdo): ?array {
        if (isset($_SESSION['tenant_id'])) {
            $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
            $stmt->execute([$_SESSION['tenant_id']]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        return null;
    }
}

if (!function_exists('set_tenant_session')) {
    function set_tenant_session(int $tenant_id, string $tenant_name, string $plan = 'basic'): void {
        $_SESSION['tenant_id'] = $tenant_id;
        $_SESSION['tenant_name'] = $tenant_name;
        $_SESSION['tenant_plan'] = $plan;
    }
}

if (!function_exists('tenant_filter')) {
    /**
     * Condición WHERE por tenant. FAIL-CLOSED: sin tenant en sesión no devuelve datos
     * (antes devolvía "1=1" y exponía todo).
     */
    function tenant_filter(string $table = ''): string {
        if (isset($_SESSION['tenant_id']) && (int)$_SESSION['tenant_id'] > 0) {
            $tid = (int)$_SESSION['tenant_id'];
            if ($table) {
                $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
                return " `$table`.tenant_id = $tid ";
            }
            return " tenant_id = $tid ";
        }
        return " 1=0 ";
    }
}
?>
