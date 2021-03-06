<?php
require_once('lib.php');
require_once('../lib/coursecatlib.php');
require_once('../course/lib.php');
require_once('../user/lib.php');
require_once('../group/lib.php');
require_once("../enrol/locallib.php");
require_once("../enrol/externallib.php");

function get_or_die($param)
{
    return isset($_GET[$param]) ? $_GET[$param] : die("Parâmetros incompletos ($param).");
}


function merge_objects($source, $destin)
{
    foreach (get_object_vars($source) as $attr => $value) {
        if ($attr == 'id') {
            $attr = 'id_moodle';
        }
        $destin->$attr = $value;
    }
}


function cmp_by_label($a, $b)
{
    return strcmp($a->getLabel(), $b->getLabel());
}


function ler_courses()
{
    global $DB;
    return $DB->get_records_sql("SELECT id, fullname, idnumber, id_suap FROM {course} ORDER BY fullname");
}


function ler_categories()
{
    global $DB;
    return $DB->get_records('course_categories');
}


$polos = null;
$poloNull = new Polo(0, "");
function getPoloById($id) {
    global $polos, $poloNull;
    if (!$polos) {
        $polos = Polo::ler_rest();
    }
    foreach ($polos as $polo) {
        if ($polo->id_on_suap == $id) {
            return $polo;
        }
    }
    return $poloNull;
}


class AbstractEntity
{
    public $id_on_suap;
    public $id_moodle;

    function __construct($id_on_suap)
    {
        $this->id_on_suap = $id_on_suap;
    }

    function ja_associado()
    {
        $instance = $this->ler_moodle();
        return $instance && $instance->id_moodle;
    }

    static function ler_rest_generico($service, $id, $class, $properties)
    {
        $response = json_request($service, ['id_diario' => $id]);
        $result = [];
        foreach ($response as $id => $obj) {
            $instance = new $class($id);
            foreach ($properties as $property) {
                $instance->{$property} = $obj[$property];
            }
            $result[] = $instance;
        }
        return $result;
    }

    function execute($sql, $params)
    {
        global $DB;
        return $DB->execute($sql, $params);
    }

    function get_records_sql($sql, $params)
    {
        global $DB;
        return $DB->get_records_sql($sql, $params);
    }

    function get_records($tablename, $filters)
    {
        $params = [];
        $where = '';
        foreach ($filters as $fieldname => $value) {
            $where .= $where == '' ? "WHERE $fieldname = ?" : " AND $fieldname = ?";
            $params[] = $value;
        }
        return $this->get_records_sql("SELECT * FROM {{$tablename}} $where",
            $params);
    }

    function get_record($tablename, $filters)
    {
	     $array = $this->get_records($tablename, $filters);
        return array_shift($array);
    }

    function getIdSUAP()
    {
        $clasname = strtolower(get_class($this));
        return "{'{$clasname}':'{$this->id_on_suap}'}";
    }

    function associar()
    {
        $tablename = $this->getTablename();
        $this->execute("UPDATE {{$tablename}} SET id_suap=NULL WHERE id_suap=?",
            [$this->getIdSUAP()]);
        $this->execute("UPDATE {{$tablename}}  SET id_suap=? WHERE id=?",
            [$this->getIdSUAP(), $this->id_moodle]);
    }

    function ler_moodle()
    {
        $table = $this->getTablename();
        $filter = ['id_suap' => $this->getIdSUAP()];
        $instance = $this->get_record($table, $filter);
        if (!$instance) {
            $rows = $this->get_records($table, ['idnumber' => $this->getCodigo()]);
            if (count($rows) == 1) {
                $this->execute("UPDATE {{$table}} SET id_suap=? WHERE idnumber=?",
                    [$this->getIdSUAP(), $this->getCodigo()]);
                $instance = $this->get_record($table, $filter);
            }
            if (!$instance) {
                return $this;
            }
        }
        merge_objects($instance, $this);
        $this->context = $this->get_record('context',
            ['contextlevel' => $this->getContextLevel(),
                'instanceid' => $this->id_moodle]);
        return $this;

    }
}


class Polo extends AbstractEntity
{
    public $nome;

    function __construct($id_on_suap, $nome)
    {
        parent::__construct($id_on_suap);
        $this->nome = $nome;
    }

    public static function ler_rest()
    {
        global $polos;
        if (!$polos) {
            $response = json_request("listar_polos_ead", array());
            $result = [];
            foreach ($response as $id_on_suap => $obj) {
                $result[] = new Polo($id_on_suap, $obj['descricao']);
            }
            $polos = $result;
        }
        return $polos;
    }
}


class Campus extends AbstractEntity
{
    public $nome;
    public $sigla;

    function __construct($id_on_suap, $nome, $sigla)
    {
        parent::__construct($id_on_suap);
        $this->nome = $nome;
        $this->sigla = $sigla;
    }

    public static function ler_rest()
    {
        $response = json_request("listar_campus_ead");
        $result = [];
        foreach ($response as $id_on_suap => $obj) {
            $result[] = new Campus($id_on_suap, $obj['descricao'], $obj['sigla']);
        }
        return $result;
    }
}


class ComponenteCurricular extends AbstractEntity
{
    public $tipo;
    public $periodo;
    public $qtd_avaliacoes;
    public $descricao_historico;
    public $optativo;
    public $descricao;
    public $sigla;

    function __construct($id_on_suap=NULL, $tipo=NULL, $periodo=NULL, $qtd_avaliacoes=NULL, $descricao_historico=NULL, $optativo=NULL, $descricao=NULL, $sigla=NULL)
    {
        parent::__construct($id_on_suap);
        $this->tipo = $tipo;
        $this->periodo = $periodo;
        $this->qtd_avaliacoes = $qtd_avaliacoes;
        $this->descricao_historico = $descricao_historico;
        $this->optativo = $optativo;
        $this->descricao = $descricao;
        $this->sigla = $sigla;
    }

    public static function ler_rest($id_curso)
    {
        $response = json_request("listar_componentes_curriculares_ead",
            array('id_curso' => $id_curso));
        $result = [];
        foreach ($response as $id_on_suap => $o) {
            $result[] = new ComponenteCurricular($id_on_suap, $o['tipo'], $o['periodo'], $o['qtd_avaliacoes'], $o['descricao_historico'], $o['optativo'], $o['descricao'], $o['sigla']);
        }
        return $result;
    }
}


class Category extends AbstractEntity
{
    public $codigo;

    function __construct($id_on_suap=NULL, $codigo=NULL)
    {
        parent::__construct($id_on_suap);
        $this->codigo = $codigo;
    }

    function getTablename()
    {
        return "course_categories";
    }

    function getContextLevel()
    {
        return '40';
    }

    function getCodigo()
    {
        return $this->codigo;
    }

    public static function render_selectbox($level = 0)
    {
        global $DB;
        $has_suap_ids = array_keys($DB->get_records_sql('SELECT id FROM {course_categories} WHERE id_suap IS NOT NULL'));
        foreach (coursecat::make_categories_list('moodle/category:manage') as $key => $label):
            if (($level > 0) && (count(split(' / ', $label)) != $level)) {
                continue;
            }
            $jah_associado = in_array($key, $has_suap_ids) ? "disabled" : "";
            echo "<label class='as_row $jah_associado' ><input type='radio' value='$key' name='categoria' $jah_associado />$label</label>";
        endforeach;
    }
}


class Curso extends Category
{
    public $nome;
    public $descricao;

    function __construct($id_on_suap=NULL, $codigo=NULL, $nome=NULL, $descricao=NULL)
    {
        parent::__construct($id_on_suap, $codigo);
        $this->nome = $nome;
        $this->descricao = $descricao;
    }

    function getLabel()
    {
        return $this->descricao;
    }

    static function ler_rest($ano_letivo, $periodo_letivo)
    {
        global $suap_id_campus_ead;
        $response = json_request("listar_cursos_ead",
            ['id_campus' => $suap_id_campus_ead,
                'ano_letivo' => $ano_letivo,
                'periodo_letivo' => $periodo_letivo]);
        $result = [];
        foreach ($response as $id_on_suap => $o) {
            $result[] = new Curso($id_on_suap, $o['codigo'], $o['nome'], $o['descricao']);
        }
        usort($result, 'cmp_by_label');
        return $result;
    }

    function importar($ano, $periodo)
    {
        $this->ler_moodle();
        echo "<li>Curso <b>{$this->name}</b> do período <b>$ano.$periodo</b><ol>";
        foreach (Turma::ler_rest($this->id_on_suap, $ano, $periodo, $this) as $turma) {
            $turma->importar();
        };
        echo "</ol></li>";
    }

    function preview($ano, $periodo)
    {   
        $this->ler_moodle();
        echo "<li>Curso <b>{$this->name}</b> diários do período <b>$ano.$periodo</b>";
        echo "<ol>";
        foreach (Turma::ler_rest($this->id_on_suap, $ano, $periodo, $this) as $turma) {
            $turma->preview();
        };
        echo "</ol></li>";
    }   


    function auto_associar($ano_inicial, $periodo_inicial, $ano_final, $periodo_final)
    {
        global $DB;
        $ano_inicial = (int)$ano_inicial;
        $periodo_inicial = (int)$periodo_inicial;
        $ano_final = (int)$ano_final;
        $periodo_final = (int)$periodo_final;

        for ($ano = $ano_inicial; $ano <= $ano_final; $ano++) {
            for ($periodo = 1; $periodo <= 2; $periodo++) {
                if (($ano == $ano_inicial && $periodo < $periodo_inicial) || ($ano == $ano_final && $periodo > $periodo_final)) {
                    continue;
                }
                foreach (Turma::ler_rest($this->id_on_suap, $ano, $periodo) as $turma_suap) {
                    $turma_suap->ler_moodle();
                    if ($turma_suap->ja_associado()) {
                        echo "<li class='notifysuccess'>A turma SUAP <strong>{$turma_suap->codigo}</strong> JÁ está associada à categoria <strong>{$turma_suap->name}</strong> no Moodle.<ol>";
                    } else {
                        echo "<li class='notifyproblem'>A turma SUAP <strong>{$turma_suap->codigo}</strong> NÃO FOI associada a uma categoria no Moodle.<ol>";
                    }
                    $diarios = Diario::ler_rest($turma_suap);
                    if (count($diarios) == 0) {
                        echo "<li class='notifymessage'>Não existem diários para esta turma.</li>";
                    }
                    foreach ($diarios as $diario_suap):
                        $diario_suap->ler_moodle();
                        if ($diario_suap->ja_associado()) {
                            echo "<li class='notifysuccess'>O diário SUAP <b>{$diario_suap->getCodigo()}</b> JÁ está associado ao course <b>{$diario_suap->getFullname()}</b> no Moodle.";
                        } else {
                            echo "<li class='notifyproblem'>O <b>diário SUAP {$diario_suap->getCodigo()}</b> NÃO está associado a um <b>course no Moodle</b>.";
                        }
                        echo "</li>";
                    endforeach;
                    echo "</ol></li>";
                };
            }
        }
    }
}

class Turma extends Category
{
    public $curso;

    function __construct($id_on_suap, $codigo, $curso=null)
    {
        parent::__construct($id_on_suap, $codigo);
        $this->curso = $curso;
    }

    function getLabel()
    {
        return $this->codigo;
    }

    function getNome() {
        return "Turma: $this->codigo";
    }

    static function ler_rest($id_curso, $ano_letivo, $periodo_letivo, $curso = null)
    {
        $response = json_request("listar_turmas_ead",
            ['id_curso' => $id_curso, 'ano_letivo' => $ano_letivo, 'periodo_letivo' => $periodo_letivo]);
        $result = [];
        foreach ($response as $id_on_suap => $obj) {
            $result[] = new Turma($id_on_suap, $obj['codigo'], $curso);
        }
        usort($result, 'cmp_by_label');
        return $result;
    }

    function importar()
    {
        $this->ler_moodle();
        echo "<li>Turma ";
        if (!$this->id_moodle) {
            $this->criar();
            echo "CRIADA ";
        } else {
            echo "EXISTENTE ";
        }
        echo "<u><a href='../course/management.php?categoryid={$this->id_moodle}'>{$this->codigo}</a></u>";
        echo "<ol>";
        foreach (Diario::ler_rest($this) as $diario) {
            $diario->importar();
        };
        echo "</ol></li>";
    }

    function criar()
    {
        try {
            // Cria a categoria
            $record = coursecat::create(array(
                "name" => "Turma: {$this->codigo}",
                "idnumber" => $this->codigo,
                "description" => '',
                "descriptionformat" => 1,
                "parent" => $this->curso->id_moodle,
            ));
            $this->id_moodle = $record->id;

            // Associa ao SUAP
            $this->associar();

        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    function preview() {
        // Se não existe uma category para esta turma criá-la como filha do curso
        $this->ler_moodle();
        echo "<li'>Turma ";
        echo !$this->ja_associado() ? 'NOVA' : "<a href='../course/management.php?categoryid={$this->id_moodle}' class='btn btn-mini'>EXISTENTE</a>";
        echo ": <b>{$this->codigo}</b> ";
        echo "<ol>";
        foreach (Diario::ler_rest($this) as $diario) {
            $diario->preview();
        }
        echo "</ol></li>";
    }
}


class Diario extends AbstractEntity
{
    public $sigla;
    public $situacao;
    public $descricao;
    public $turma;

    function __construct($id_on_suap, $sigla = null, $situacao = null, $descricao = null, $turma = null)
    {
        parent::__construct($id_on_suap);
        $this->sigla = $sigla;
        $this->situacao = $situacao;
        $this->descricao = $descricao;
        $this->turma = $turma;
    }

    function getTablename()
    {
        return "course";
    }

    function getLabel()
    {
        return $this->sigla;
    }

    function getCodigo()
    {
        return $this->turma ? "{$this->turma->codigo}.{$this->sigla}" : NULL;
    }

    function getContextLevel()
    {
        return '50';
    }

    static function ler_rest($turma)
    {
        $response = json_request("listar_diarios_ead", ['id_turma' => $turma->id_on_suap]);
        $result = [];
        foreach ($response as $id_on_suap => $obj) {
            $result[] = new Diario($id_on_suap, $obj['sigla'], $obj['situacao'], $obj['descricao'], $turma);
        }
        usort($result, 'cmp_by_label');
        return $result;
    }

    function importar()
    {
        $this->ler_moodle();
        echo "<li>Diário ";
        if ($this->ja_associado()) {
            echo "EXISTENTE.";
        } else {
            $this->criar();
            echo "CRIADO.";
        }
        echo "<u><a href='../course/view.php?id={$this->id_moodle}'>{$this->getFullname()}</a></u>";
        echo "<ol>";
        Professor::sincronizar($this);
        Aluno::sincronizar($this);
        echo "</ol></li>";
    }

    function getFullname() {
        return $this->descricao;
    }

    function criar()
    {
        // Criar período
        $periodo_numero = explode('.', $this->turma->getCodigo())[1];
        $periodo_nome = "{$periodo_numero}º período";
        $turma_id = $this->turma->id_moodle;
        $periodo_params = ["parent" => $turma_id, 'name' => $periodo_nome];

        $periodo = $this->get_record('course_categories', $periodo_params);
        if (!$periodo) {
            $periodo = coursecat::create($periodo_params);
        }

        // Criar o diário
        $dados = (object)array(
            'category'=>$periodo->id,
            'fullname'=>$this->getFullname(),
            'shortname'=>$this->getCodigo(),
            'idnumber'=>$this->getCodigo(),
        );

        $record = create_course($dados);

        // Associa ao SUAP
        $this->id_moodle = $record->id;
        $this->associar($record->id);
    }

    function preview() {
        global $polos;
        $periodo_numero = explode('.', $this->turma->getCodigo())[1];
        $periodo_nome = "{$periodo_numero}º período";
        $turma_id = $this->turma->id_moodle;
        $periodo_params = ["parent" => $turma_id, 'name' => $periodo_nome];
        echo "<li'>";
        $operacao = !$this->ja_associado() ? 'NOVO' : "<a class='btn btn-mini' href='http://ead.ifrn.edu.br/ava/academico/course/view.php?id={$this->id_moodle}'>EXISTENTE</a>";
        echo "Diário $operacao: <b>{$this->descricao}</b> - categoria: (<i>{$this->turma->getNome()}</i> / <i>{$periodo_numero}º período</i>)";
        echo "<br/>";
        echo "<table class='table'>";
        $this->preview_users('Professores', Professor::ler_rest($this->id_on_suap));
        $this->preview_users('Alunos', Aluno::ler_rest($this->id_on_suap));
        echo "</tbody></table>";
        echo "</li>";
    }
    
    function preview_users($title, $users) {
        if (count($users)==0) {
            echo "<thead><tr><td colspan='7'>Não há <b>$title</b> a importar.</td></tr></thead>";
            return;
        }
        echo "<thead><tr><td colspan='7'><br/><b>$title</b></td></tr><tr><th>#</th><th>Nome</th><th>IFRN-id</th><th>No Moodle</th><th>No diário</th><th>e-Mail</th><th>Status</th></tr></thead>";
        echo "<tbody>";
        $i = 0;
        foreach ($users as $user) {
            $tipo = strtolower($user->tipo);
            $novo = $user->ja_criado() ? 'Atualizar' : 'Cadastrar';
            $arrolado = $user->ja_arrolado($this) ? 'Pular' : 'Matricular';
            $i++;
            echo "<tr><td width='1'>$i</td><td>{$user->nome}</td><td>{$user->login}{$user->matricula}</td><td>$novo</td><td>$arrolado</td><td>{$user->email_secundario}</td><td>$tipo {$user->situacao}{$user->status}</td></li>";
        }
        echo "</tbody>";

    }
}


class Usuario extends AbstractEntity
{
    public $id;
    public $nome;
    public $login;
    public $matricula;
    public $tipo;
    public $email;
    public $email_secundario;
    public $status;
    public $situacao;
    public $id_moodle;

    function __construct($id, $nome = null, $login = null, $tipo = null, $email = null, $email_secundario = null, $status = null)
    {
        $this->id = $id;
        $this->nome = $nome;
        $this->login = $login;
        $this->tipo = $tipo;
        $this->email = $email;
        $this->email_secundario = $email_secundario;
        $this->status = $status;
    }

    function getUsername()
    {
        return $this->login ? $this->login : $this->matricula;
    }

    function getEmail()
    {
        return $this->email ? $this->email : $this->email_secundario;
    }

    function getSuspended()
    {
        return $this->getStatus() == 'ativo' ? 0 : 1;
    }

    function getStatus()
    {
        return $this->status ? $this->status : $this->situacao;
    }

    function getTipo()
    {
        return $this->tipo ? $this->tipo : 'Aluno';
    }

    function getRoleId()
    {
        global $enrol_roleid;
        return $enrol_roleid[$this->getTipo()];
    }

    function getEnrolType()
    {
        global $enrol_type;
	if (array_key_exists($this->getTipo(), $enrol_type)) {
            return $enrol_type[$this->getTipo()];
        } else {
           die("Não existe este tipo de usuário ({$this->tipo})  no integrador.");
        }
    }

    protected static function sincronizar($diario, $oque, $list)
    {
        try {
            if (count($list) == 0) { 
                echo "<li>Não há $oque a sincronizar.</li>";
                return;
            }
            echo "<li>Sincronizando <b>" . count($list) .  " $oque</b><table class='table'>";
            echo "<tr><th width='1'>#</th><th>Oper</th><th>Nome</th><th>Tipo</th><th>Arrolado</th><th>Atribuído</th><th>Grupo</th></tr>";
            $i = 0;
            foreach ($list as $instance) {
                $i++;
                echo "<tr><td>$i</td>";
                $instance->importar();
                $instance->arrolar($diario);
                $instance->engrupar($diario);
                echo "</tr>";
            }
            echo "</table></li>";
        } catch (Exception $e) {
            raise_error($e);
        }
    }

    function getUser(){
        global $DB;
        $usuario = $DB->get_record("user", array("username" => $this->getUsername()));
        return $usuario;
    }

    function ja_criado() {
        return !empty($this->getUser());
    }

    function importar()
    {
        global $default_user_preferences;
        $usuario = $this->getUser();
        $nome_parts = explode(' ', $this->nome);
        $lastname = array_pop($nome_parts);
        $firstname = implode(' ', $nome_parts);
        if (!$usuario) {
            $this->id_moodle = user_create_user([
                'lastname'=>$lastname,
                'firstname'=>$firstname,
                'username'=>$this->getUsername(),
                'auth'=>'ldap',
                'password'=>'not cached',
                'email'=>$this->getEmail(),
                'suspended'=>$this->getSuspended(),
                'timezone'=>'99',
                'lang'=>'pt_br',
                'confirmed'=>1,
                'mnethostid'=>1,
            ], false);

            foreach ($default_user_preferences as $key=>$value) {
                $this->criar_user_preferences($key, $value);
            }
            $usuario->id = $this->id_moodle;
            $oper = 'Criado';
        } else {
            user_update_user([
                'id'=>$usuario->id,
                'suspended'=>$this->getSuspended(),
                'lastname'=>$lastname,
                'firstname'=>$firstname,
                'mnethostid'=>1,
            ], false);
            $oper = 'Atualizado';
        }

        echo "<td>$oper</td><td><a href='../user/profile.php?id={$usuario->id}'>{$this->getUsername()} - {$this->nome}</a></td><td>{$this->getTipo()}</td>";
        $this->id_moodle = $usuario->id;
    }

    function criar_user_preferences($name, $value)
    {
        global $DB;
        $DB->insert_record('user_preferences',
                           (object)array( 'userid'=>$this->id_moodle, 'name'=>$name, 'value'=>$value, ));
    }

    function ja_arrolado($diario) {
        global $DB;
        return !$DB->get_record('enrol', array('enrol'=>$this->getEnrolType(), 'courseid'=>$diario->id_moodle, 'roleid'=>$this->getRoleId()));
    }

    function arrolar($diario) {
        global $DB, $USER;
        $enrol = Enrol::ler_ou_criar($this->getEnrolType(), $diario->id_moodle, $this->getRoleId());

        $enrolment = $DB->get_record('user_enrolments', array('enrolid'=>$enrol->id,'userid'=>$this->id_moodle));
        if (!$enrolment) {
            $id = $DB->insert_record('user_enrolments',
                                     (object)['enrolid'=>$enrol->id,
                                              'userid'=>$this->id_moodle,
                                              'status'=>0,
                                              'timecreated'=>time(),
                                              'timemodified'=>time(),
                                              'timestart'=>time(),
                                              'modifierid'=>$USER->id,
                                              'timeend'=>0,]);
            echo "<td>Foi arrolado</td>";
        } else {
            echo "<td>Já arrolado</td>";
        }
        $diario->ler_moodle();
        $assignment = $DB->get_record('role_assignments',
            array('roleid'=>$this->getRoleId(), 'contextid'=>$diario->context->id, 'userid'=>$this->id_moodle, 'itemid'=>0));
        $diario->ja_associado();
        if (!$assignment) {
            $id2 = $DB->insert_record('role_assignments',
                                      (object)['roleid'=>$this->getRoleId(),
                                               'contextid'=>$diario->context->id,
                                               'userid'=>$this->id_moodle,
                                               'itemid'=>0,]);
            echo "<td>Foi atribuído</td>";
        } else {
            echo "<td>Já atribuído</td>";
        }
    }

    function engrupar($diario)
    {
        global $DB, $USER;
        $polo = $this->getPolo();
        if ($polo) {
            $data = (object)['courseid' => $diario->id_moodle, 'name' => $polo->nome];
            $group = $this->get_record('groups', $data);
            if (!$group) {
                groups_create_group($data);
                $group = $this->get_record('groups', $data);
            }
            if ($this->get_record('groups_members', ['groupid' => $group->id, 'userid' => $this->id_moodle, ])) {
                echo "<td>Já estava no grupo</td><td>{$polo->nome}</td>";
            } else {
                echo "<td>Adicionado ao grupo</td><td>{$polo->nome}</td>";
                groups_add_member($group->id, $this->id_moodle);
            }
        }
    }
}


class Professor extends Usuario
{
    public function getPolo() {
        return null;
    }

    public static function ler_rest($id_diario)
    {
        return AbstractEntity::ler_rest_generico("listar_professores_ead", $id_diario, 'Professor', ['nome', 'login', 'tipo', 'email', 'email_secundario', 'status']);
    }

    public static function sincronizar($diario, $oque=null, $list=null)
    {
        Usuario::sincronizar($diario, 'docentes', Professor::ler_rest($diario->id_on_suap));
    }
}


class Aluno extends Usuario
{
    public $polo;

    public function getPolo() {
        $polos = Polo::ler_rest();
        foreach ($polos as $attr => $polo) {
            if (intval($polo->id_on_suap) == intval ($this->polo)) {
                return $polo;
            }
        }
        return null;
    }

    public static function ler_rest($id_diario)
    {
        return AbstractEntity::ler_rest_generico("listar_alunos_ead", $id_diario, 'Aluno',
                                                 ['nome', 'matricula', 'email', 'email_secundario', 'situacao', 'polo']);

    }

    public static function sincronizar($diario, $oque=null, $list=null)
    {
        Usuario::sincronizar($diario, 'alunos', Aluno::ler_rest($diario->id_on_suap));
    }
}

class Enrol extends AbstractEntity
{
    public $id;
    public $enroltype;
    public $courseid;
    public $roleid;

    public static function ler_ou_criar($enroltype,  $courseid,  $roleid)
    {
        global $DB;
        $enrol = $DB->get_record('enrol', array('enrol'=>$enroltype, 'courseid'=>$courseid, 'roleid'=>$roleid));
        if (!$enrol) {
            $enrol = new stdClass();
            $enrol->enrol = $enroltype;
            $enrol->courseid = $courseid;
            $enrol->roleid = $roleid;
            $enrol->status = 0;
            $enrol->expirythreshold = 86400;
            $enrol->timecreated = time();
            $enrol->timemodified = time();
            $enrol->id = $DB->insert_record('enrol', $enrol);
        }
        return $enrol;
    }
}
