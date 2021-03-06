<?php
require_once("../config.php");
@error_reporting(E_ALL | E_STRICT);
@ini_set('display_errors', '1');
$CFG->debug = (E_ALL | E_STRICT);
$CFG->debugdisplay = 1;

require_once("models.php");
require_login();

$context = context_system::instance();

//if (!has_capability('blocks/suap:viewpage', $context)) {
//  print_error(get_string('notallowed', 'block_suap'));
//}

$PAGE->set_context($context);
// $PAGE->set_pagelayout('standard');
$PAGE->set_title("Integração com SUAP");
$PAGE->set_url(new moodle_url('/suap/index.php'));
$PAGE->set_heading('Integração com SUAP');
$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin('ui');
$PAGE->requires->jquery_plugin('ui-css');
// $PAGE->requires->js("/suap/js/json.js");
// $PAGE->requires->js_init_call('M.block_suap.init');
$PAGE->requires->css('/suap/style.css');
echo $OUTPUT->header();
$listar_cursos_active = !isset($listar_campus_active) && !isset($listar_polos_active) ? 'active' : '';
?>
<style>
body {margin-left: 0 !important}
#nav-drawer{display: none !important;}
</style>
<ul class="nav nav-tabs" role="tablist">
<li class="nav-item"><a href="listar_cursos.php" class="nav-link <?php echo $listar_cursos_active; ?>">Listar cursos</a></li>
    <li class="nav-item"><a href="listar_campus.php" class="nav-link <?php echo $listar_campus_active; ?>">Listar campi</a></li>
    <li class="nav-item"><a href="listar_polos.php" class="nav-link <?php echo $listar_polos_active; ?>">Listar polos</a></li>
</ul>
<br />

