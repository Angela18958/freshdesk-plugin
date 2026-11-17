<?php
/**
 * Plugin Name: FreshDesk Style Helpdesk Pro (V15.0)
 * Description: Helpdesk with Vertical Nav, Login/Register, and Agent Setup.
 * Version: 15.0
 * Author: Lebogang Matsobe
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================
// 1. CORE SYSTEM & ROLES
// ============================================

function fdv15_init() {
    register_post_type( 'fd_ticket', array(
        'labels' => array('name' => 'Tickets', 'singular_name' => 'Ticket', 'menu_name' => 'Helpdesk'),
        'public' => false, 'show_ui' => true, 'show_in_menu' => true, 'menu_icon' => 'dashicons-sos',
        'supports' => array( 'title', 'editor', 'author', 'comments' ),
    ));
    
    register_taxonomy( 'fd_ticket_status', 'fd_ticket', array('hierarchical' => true, 'label' => 'Status'));
    register_taxonomy( 'fd_ticket_priority', 'fd_ticket', array('hierarchical' => true, 'label' => 'Priority'));

    if ( !get_role( 'helpdesk_customer' ) ) add_role( 'helpdesk_customer', 'Helpdesk Customer', array('read' => true));
    if ( !get_role( 'helpdesk_agent' ) ) add_role( 'helpdesk_agent', 'Helpdesk Agent', array('read' => true, 'edit_posts' => true, 'edit_others_posts' => true));

    global $wpdb;
    $table_name = $wpdb->prefix . 'fd_agent_invites';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        email varchar(100) NOT NULL,
        token varchar(64) NOT NULL,
        status varchar(20) DEFAULT 'pending',
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}
add_action( 'init', 'fdv15_init' );

// ============================================
// 2. AUTHENTICATION (LOGIN/REGISTER)
// ============================================

add_shortcode('helpdesk_auth', 'fdv15_auth_ui');
function fdv15_auth_ui() {
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        $redirect = in_array('helpdesk_agent', $user->roles) ? home_url('/agent-dashboard/') : home_url('/submit-ticket/');
        return "<div style='text-align:center; padding:30px; border:1px solid #ddd; border-radius:10px;'>Welcome back, <strong>{$user->display_name}</strong>!<br><br><a href='$redirect' style='background:#3b82f6; color:#fff; padding:10px 20px; text-decoration:none; border-radius:5px;'>Go to Dashboard</a></div>";
    }

    ob_start(); ?>
    <style>
        .fdv-auth { max-width: 400px; margin: 0 auto; font-family: sans-serif; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: #fff; }
        .fdv-tabs { display: flex; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .fdv-tab { flex: 1; padding: 15px; text-align: center; cursor: pointer; color: #64748b; font-weight: 600; }
        .fdv-tab.active { background: #fff; color: #3b82f6; border-bottom: 2px solid #3b82f6; }
        .fdv-body { padding: 30px; }
        .fdv-input { width: 100%; padding: 12px; margin-bottom: 15px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; }
        .fdv-btn { width: 100%; padding: 12px; border: none; border-radius: 6px; background: #3b82f6; color: #fff; font-weight: 600; cursor: pointer; }
        .fdv-error { color: #dc2626; background: #fee2e2; padding: 10px; border-radius: 6px; margin-bottom: 15px; font-size: 14px; }
    </style>

    <div class="fdv-auth">
        <div class="fdv-tabs">
            <div id="tab-log" class="fdv-tab active" onclick="fdvSwitch('log')">Login</div>
            <div id="tab-reg" class="fdv-tab" onclick="fdvSwitch('reg')">Register</div>
        </div>
        <div class="fdv-body">
            <?php if(isset($_GET['err'])) echo '<div class="fdv-error">'.esc_html(str_replace('-', ' ', $_GET['err'])).'</div>'; ?>

            <form id="fdv-log" method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="fdv15_handle_login">
                <input type="text" name="u" placeholder="Username" class="fdv-input" required>
                <input type="password" name="p" placeholder="Password" class="fdv-input" required>
                <button type="submit" class="fdv-btn">Sign In</button>
            </form>

            <form id="fdv-reg" method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:none;">
                <input type="hidden" name="action" value="fdv15_handle_reg">
                <input type="text" name="n" placeholder="Full Name" class="fdv-input" required>
                <input type="email" name="e" placeholder="Email Address" class="fdv-input" required>
                <input type="text" name="u" placeholder="Choose Username" class="fdv-input" required>
                <input type="password" name="p" placeholder="Password" class="fdv-input" required>
                <button type="submit" class="fdv-btn" style="background:#10b981;">Create Account</button>
            </form>
        </div>
    </div>

    <script>
        function fdvSwitch(mode) {
            document.getElementById('fdv-log').style.display = (mode === 'log') ? 'block' : 'none';
            document.getElementById('fdv-reg').style.display = (mode === 'reg') ? 'block' : 'none';
            document.getElementById('tab-log').classList.toggle('active', mode === 'log');
            document.getElementById('tab-reg').classList.toggle('active', mode === 'reg');
        }
    </script>
    <?php return ob_get_clean();
}

// Logic: Handle Login
add_action('admin_post_nopriv_fdv15_handle_login', 'fdv15_proc_login');
function fdv15_proc_login() {
    $user = wp_signon(array('user_login' => $_POST['u'], 'user_password' => $_POST['p'], 'remember' => true), false);
    if (is_wp_error($user)) {
        wp_redirect(add_query_arg('err', 'Invalid-Login', wp_get_referer()));
    } else {
        $url = in_array('helpdesk_agent', $user->roles) ? home_url('/agent-dashboard/') : home_url('/submit-ticket/');
        wp_redirect($url);
    }
    exit;
}

// Logic: Handle Register
add_action('admin_post_nopriv_fdv15_handle_reg', 'fdv15_proc_reg'); n  
function fdv15_proc_reg() {
    if (email_exists($_POST['e']) || username_exists($_POST['u'])) {
        wp_redirect(add_query_arg('err', 'User-Already-Exists', wp_get_referer()));
        exit;
    }
    $user_id = wp_create_user($_POST['u'], $_POST['p'], $_POST['e']);
    if(!is_wp_error($user_id)) {
        wp_update_user(['ID' => $user_id, 'display_name' => sanitize_text_field($_POST['n'])]);
        (new WP_User($user_id))->set_role('helpdesk_customer');
        wp_set_current_user($user_id); wp_set_auth_cookie($user_id);
        wp_redirect(home_url('/submit-ticket/'));
    }
    exit;
}

// ============================================
// 3. AGENT DASHBOARD (WITH TICKET COUNTER)
// ============================================

add_shortcode('fd_agent_dashboard', 'fdv15_agent_dashboard');
function fdv15_agent_dashboard() {
    if ( !current_user_can('edit_others_posts') ) return "Access Denied.";

    // Logic to count the tickets
    $ticket_count = wp_count_posts('fd_ticket')->publish; 

    $nonce = wp_create_nonce('fd_ajax_nonce');
    ob_start(); ?>
    <div class="fd-pro-dashboard">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            :root { --fd-main: #3b82f6; --fd-sidebar-bg: #1e293b; --fd-bg: #f9fafb; --fd-border: #e5e7eb; }
            .fd-pro-dashboard { font-family: 'Inter', sans-serif; background: #fff; border: 1px solid var(--fd-border); border-radius: 12px; display: flex; min-height: 800px; width: 100%; overflow: hidden; position: relative; z-index: 10;}
            .fd-sidebar { width: 240px; background: var(--fd-sidebar-bg); display: flex; flex-direction: column; color: #fff; flex-shrink: 0; }
            .fd-sidebar-logo { padding: 30px 20px; font-weight: 800; font-size: 16px; color: var(--fd-main); border-bottom: 1px solid #334155; text-align: center; }
            .fd-menu { flex: 1; padding: 20px 0; }
            .fd-menu-item { display: flex; align-items: center; padding: 14px 25px; color: #94a3b8; text-decoration: none; cursor: pointer; border-left: 4px solid transparent; font-size: 14px; }
            .fd-menu-item i { width: 25px; }
            .fd-menu-item:hover, .fd-menu-item.active { background: #334155; color: #fff; }
            .fd-menu-item.active { border-left-color: var(--fd-main); }
            
            /* The Counter Badge Style */
            .fd-counter { background: #ef4444; color: #fff; font-size: 10px; font-weight: bold; padding: 2px 8px; border-radius: 10px; margin-left: auto; }

            .fd-view-container { flex: 1; background: var(--fd-bg); display: flex; flex-direction: column; min-width: 0; }
            .fd-view-header { background: #fff; padding: 20px 30px; border-bottom: 1px solid var(--fd-border); display: flex; justify-content: space-between; align-items: center; }
            .fd-view-body { padding: 30px; flex: 1; overflow-y: auto; }
            .fd-panel { display: none; }
            .fd-panel.active { display: block; }
            .fd-data-card { background: #fff; border-radius: 10px; border: 1px solid var(--fd-border); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
            .fd-table { width: 100%; border-collapse: collapse; }
            .fd-table th { background: #f9fafb; text-align: left; padding: 15px; font-size: 11px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid var(--fd-border); }
            .fd-table td { padding: 15px; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
            .fd-status-select { padding: 5px; border-radius: 4px; border: 1px solid #ddd; font-size: 12px; }
            .fd-modal { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; }
            .fd-modal-content { background: #fff; width: 800px; max-height: 85vh; border-radius: 15px; padding: 40px; position: relative; overflow-y: auto; }
            .fd-input-dash { padding: 10px; border: 1px solid #ddd; border-radius: 6px; width: 100%; margin-bottom: 15px; }
            .fd-btn-dash { background: var(--fd-main); color: #fff; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; }
            .chart-rail { background: #eee; height: 10px; border-radius: 10px; overflow: hidden; margin-top: 5px; }
            .chart-fill { background: var(--fd-main); height: 100%; }
        </style>

        <aside class="fd-sidebar">
            <div class="fd-sidebar-logo">HELPDESK STAFF</div>
            <nav class="fd-menu">
                <div class="fd-menu-item active" data-target="p-tickets" data-title="Tickets">
                    <i class="fas fa-ticket-alt"></i> Tickets 
                    <?php if($ticket_count > 0): ?>
                        <span class="fd-counter"><?php echo $ticket_count; ?></span>
                    <?php endif; ?>
                </div>
                <div class="fd-menu-item" data-target="p-contacts" data-title="Customers"><i class="fas fa-users"></i> Contacts</div>
                <div class="fd-menu-item" data-target="p-invites" data-title="Agent Invitations"><i class="fas fa-user-plus"></i> Team Members</div>
                <div class="fd-menu-item" data-target="p-reports" data-title="Reports"><i class="fas fa-chart-pie"></i> Reports</div>
                <a href="<?php echo wp_logout_url(home_url()); ?>" class="fd-menu-item" style="margin-top:auto;"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </nav>
        </aside>

        <div class="fd-view-container">
            <header class="fd-view-header"><h2 id="fd-v-title" style="margin:0; font-size: 18px;">Tickets</h2><div id="save-note" style="color:#10b981; font-weight:bold; display:none;">Saved!</div></header>
            <div class="fd-view-body">
                <div id="p-tickets" class="fd-panel active">
                    <div class="fd-data-card"><table class="fd-table"><thead><tr><th>ID</th><th>Subject</th><th>Requester</th><th>Status Update</th></tr></thead><tbody id="fdTicketList"><?php echo fdv15_get_ticket_rows(); ?></tbody></table></div>
                </div>
                <div id="p-contacts" class="fd-panel">
                    <div class="fd-data-card"><table class="fd-table"><thead><tr><th>Name</th><th>Email</th><th>Tickets</th></tr></thead><tbody><?php echo fdv15_get_contact_rows(); ?></tbody></table></div>
                </div>
                <div id="p-invites" class="fd-panel">
                    <div style="max-width:500px; background:#fff; padding:25px; border-radius:12px; border:1px solid var(--fd-border); margin-bottom:30px;">
                        <h4 style="margin:0 0 10px 0;">Invite New Agent</h4>
                        <input type="email" id="invite_email" class="fd-input-dash" placeholder="Agent email address...">
                        <button id="btn_send_invite" class="fd-btn-dash">Send Invite</button>
                    </div>
                    <div class="fd-data-card"><table class="fd-table"><thead><tr><th>Email</th><th>Status</th><th>Date</th></tr></thead><tbody><?php echo fdv15_get_invite_rows(); ?></tbody></table></div>
                </div>
                <div id="p-reports" class="fd-panel">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:25px;">
                        <div class="fd-data-card" style="padding:25px;"><h4>Status Breakdown</h4><?php echo fdv15_draw_report('fd_ticket_status', ['Open', 'Pending', 'Resolved', 'Closed']); ?></div>
                        <div class="fd-data-card" style="padding:25px;"><h4>Priority Overview</h4><?php echo fdv15_draw_report('fd_ticket_priority', ['Low', 'Medium', 'High', 'Urgent']); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="fdModal" class="fd-modal"><div class="fd-modal-content"><span id="closeModal" style="float:right; cursor:pointer; font-size:30px;">&times;</span><div id="fdModalBody"></div></div></div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        const nonce = '<?php echo $nonce; ?>';

        document.querySelectorAll('.fd-menu-item[data-target]').forEach(btn => {
            btn.onclick = function() {
                document.querySelectorAll('.fd-menu-item').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.fd-panel').forEach(p => p.classList.remove('active'));
                this.classList.add('active');
                document.getElementById(this.dataset.target).classList.add('active');
                document.getElementById('fd-v-title').innerText = this.dataset.title;
            };
        });

        document.addEventListener('change', function(e) {
            if(e.target.classList.contains('fd-status-select')) {
                const fd = new FormData();
                fd.append('action', 'fdv15_quick_update');
                fd.append('id', e.target.dataset.id);
                fd.append('val', e.target.value);
                fd.append('nonce', nonce);
                fetch(ajaxUrl, { method: 'POST', body: fd }).then(() => {
                    document.getElementById('save-note').style.display = 'block';
                    setTimeout(() => { document.getElementById('save-note').style.display = 'none'; }, 2000);
                });
            }
        });

        const inviteBtn = document.getElementById('btn_send_invite');
        if(inviteBtn) {
            inviteBtn.onclick = function() {
                const email = document.getElementById('invite_email').value;
                if(!email) return;
                const fd = new FormData();
                fd.append('action', 'fdv15_send_invite');
                fd.append('email', email);
                fd.append('nonce', nonce);
                fetch(ajaxUrl, { method: 'POST', body: fd }).then(r => r.json()).then(res => { alert(res.data); location.reload(); });
            };
        }

        document.addEventListener('click', function(e) {
            const tr = e.target.closest('#fdTicketList tr');
            if (tr && !e.target.classList.contains('fd-status-select')) {
                document.getElementById('fdModal').style.display = 'flex';
                fetch(ajaxUrl + '?action=fdv15_load_ticket&id=' + tr.dataset.id + '&nonce=' + nonce)
                .then(r => r.json()).then(data => { document.getElementById('fdModalBody').innerHTML = data.data.html; });
            }
            if(e.target.id === 'closeModal' || e.target.id === 'fdModal') document.getElementById('fdModal').style.display = 'none';
        });
    });
    </script>
    <?php return ob_get_clean();
}

// ============================================
// 4. AJAX & DATA LOGIC
// ============================================

add_action('wp_ajax_fdv15_quick_update', function() {
    check_ajax_referer('fd_ajax_nonce', 'nonce');
    wp_set_object_terms(intval($_POST['id']), sanitize_text_field($_POST['val']), 'fd_ticket_status');
    wp_send_json_success();
});

add_action('wp_ajax_fdv15_send_invite', function() {
    check_ajax_referer('fd_ajax_nonce', 'nonce');
    global $wpdb; $email = sanitize_email($_POST['email']); $token = bin2hex(random_bytes(16));
    $wpdb->insert($wpdb->prefix.'fd_agent_invites', ['email' => $email, 'token' => $token, 'status' => 'pending']);
    wp_send_json_success("Invitation simulated. In a real server, an email would be sent to $email.");
});

add_action('wp_ajax_fdv15_load_ticket', function() {
    $id = intval($_GET['id']); $p = get_post($id);
    ob_start(); ?>
    <h3>#<?php echo $id; ?> - <?php echo esc_html($p->post_title); ?></h3>
    <div style="background:#f9fafb; padding:20px; border:1px solid #eee; border-radius:8px;"><?php echo wpautop($p->post_content); ?></div>
    <?php wp_send_json_success(['html' => ob_get_clean()]);
});

function fdv15_get_ticket_rows() {
    $tickets = get_posts(['post_type' => 'fd_ticket', 'posts_per_page' => 30]);
    $html = ''; $statuses = ['Open', 'Pending', 'Resolved', 'Closed'];
    foreach($tickets as $t) {
        $st = get_the_terms($t->ID, 'fd_ticket_status')[0]->name ?? 'Open';
        $author = get_userdata($t->post_author)->display_name ?? 'Unknown';
        $html .= "<tr data-id='{$t->ID}'><td>#{$t->ID}</td><td><strong>".esc_html($t->post_title)."</strong></td><td>$author</td><td><select class='fd-status-select' data-id='{$t->ID}'>";
        foreach($statuses as $o) $html .= "<option ".selected($st,$o,false).">$o</option>";
        $html .= "</select></td></tr>";
    }
    return $html ?: "<tr><td colspan='4'>No tickets found.</td></tr>";
}

function fdv15_get_contact_rows() {
    $users = get_users(['role' => 'helpdesk_customer', 'number' => 20]);
    $html = '';
    foreach($users as $u) {
        $count = (new WP_Query(['post_type'=>'fd_ticket','author'=>$u->ID]))->found_posts;
        $html .= "<tr><td>{$u->display_name}</td><td>{$u->user_email}</td><td>$count</td></tr>";
    }
    return $html ?: "<tr><td colspan='3'>No customers.</td></tr>";
}

function fdv15_get_invite_rows() {
    global $wpdb; $res = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fd_agent_invites ORDER BY id DESC LIMIT 5");
    $html = '';
    foreach($res as $r) $html .= "<tr><td>{$r->email}</td><td>".ucfirst($r->status)."</td><td>{$r->created_at}</td></tr>";
    return $html ?: "<tr><td colspan='3'>No invites.</td></tr>";
}

function fdv15_draw_report($tax, $terms) {
    $total = wp_count_posts('fd_ticket')->publish;
    $html = '';
    foreach($terms as $t) {
        $count = (new WP_Query(['post_type'=>'fd_ticket','tax_query'=>[['taxonomy'=>$tax,'field'=>'name','terms'=>$t]]]))->found_posts;
        $perc = ($total > 0) ? ($count / $total) * 100 : 0;
        $html .= "<div style='margin-bottom:15px;'><div style='display:flex; justify-content:space-between; font-size:12px;'><span>$t</span> <span>$count</span></div><div class='chart-rail'><div class='chart-fill' style='width:{$perc}%'></div></div></div>";
    }
    return $html;
}

// ============================================
// 5. CUSTOMER TICKET FORM
// ============================================

add_shortcode('ticket_form', 'fdv15_ticket_form');
function fdv15_ticket_form() {
    if(!is_user_logged_in()) return "<div style='text-align:center; padding:40px; border:1px solid #ddd; border-radius:10px;'><h3>Members Only</h3><p>Please login to submit a ticket.</p><a href='".home_url('/login/')."' style='background:#3b82f6; color:#fff; padding:10px 20px; text-decoration:none; border-radius:5px;'>Login / Register</a></div>";
    
    if(isset($_POST['fdv_submit'])){
        $id = wp_insert_post(['post_title'=>sanitize_text_field($_POST['fdv_sub']), 'post_content'=>wp_kses_post($_POST['fdv_msg']), 'post_type'=>'fd_ticket', 'post_status'=>'publish', 'post_author'=>get_current_user_id()]);
        wp_set_object_terms($id, 'Open', 'fd_ticket_status');
        echo "<div style='background:#dcfce7; color:#166534; padding:15px; border-radius:8px; margin-bottom:20px;'>Ticket #$id Created!</div>";
    }
    ob_start(); ?>
    <form method="post" style="max-width:500px; padding:30px; border:1px solid #ddd; border-radius:12px; background:#fff; margin:auto;">
        <h3 style="margin-top:0;">New Support Request</h3>
        <input type="text" name="fdv_sub" placeholder="What is the issue?" required style="width:100%; padding:10px; margin-bottom:15px; border:1px solid #ddd; border-radius:6px;">
        <textarea name="fdv_msg" placeholder="Describe the problem in detail..." required style="width:100%; padding:10px; height:120px; margin-bottom:15px; border:1px solid #ddd; border-radius:6px;"></textarea>
        <button type="submit" name="fdv_submit" style="width:100%; background:#3b82f6; color:#fff; border:none; padding:12px; border-radius:6px; font-weight:600; cursor:pointer;">Submit Ticket</button>
    </form>
    <?php return ob_get_clean();
}