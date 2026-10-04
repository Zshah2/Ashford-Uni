<?php

declare(strict_types=1);

function admin_portal_role_label(): string
{
    if (auth_is_viewer()) {
        return 'Viewer';
    }
    if (auth_is_limited()) {
        return 'Limited Admin';
    }

    return 'Admin';
}

/** Shared layout variables for layouts/admin_portal.php */
function admin_portal_layout_context(string $navActive, ?string $pageTitle = null): array
{
    auth_start_session();

    return [
        'pageTitle' => $pageTitle ?? 'Administration — Ashford College',
        'admin_nav_active' => $navActive,
        'admin_username' => auth_portal_display_name(),
        'admin_role_label' => admin_portal_role_label(),
        'csrf' => csrf_token(),
    ];
}
