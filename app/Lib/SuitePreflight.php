<?php
declare(strict_types=1);
namespace App\Lib;

use PDO;
use Throwable;

/** Read-only local checks. Passing never certifies tenant readiness. */
final class SuitePreflight
{
    public static function database(PDO $pdo,array $binding): array
    {
        $checks=[];
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        try {
            new SuiteGateway($binding);
            if ($binding['local_project_id']!==1) throw new \RuntimeException();
            $checks['binding_configuration']=true;
        } catch (Throwable $e) {return ['binding_configuration'=>false];}
        try {
            foreach (['apartments','audit_log','baselines','calendars','calendar_holidays','comments','contractors','dependencies','imports','projects','tasks','templates','template_dependencies','template_tasks','users','suite_instance_binding'] as $table) {
                $pdo->query('SELECT id FROM '.$table.' LIMIT 0');
            }
            $pdo->query('SELECT suite_user_id,local_user_id FROM suite_user_map LIMIT 0');
            $checks['application_tables']=true;
        } catch (Throwable $e) { $checks['application_tables']=false;return $checks; }
        try {
            $rows=$pdo->query('SELECT id,instance_id,organization_id,project_id,local_project_id FROM suite_instance_binding')->fetchAll(PDO::FETCH_ASSOC);
            $matched=count($rows)===1 && (int)$rows[0]['id']===1;
            foreach (['instance_id','organization_id','project_id','local_project_id'] as $field) {
                $matched=$matched && (int)($rows[0][$field]??0)===$binding[$field];
            }
            $checks['database_binding']=$matched;
            $rows=$pdo->query('SELECT id FROM projects')->fetchAll(PDO::FETCH_COLUMN);
            $checks['single_bound_project']=count($rows)===1 && (int)$rows[0]===$binding['local_project_id'];
            $checks['mapping_integrity']=(int)$pdo->query('SELECT COUNT(*) FROM suite_user_map m LEFT JOIN users u ON u.id=m.local_user_id WHERE u.id IS NULL')->fetchColumn()===0;
        } catch (Throwable $e) { $checks['database_binding']=false; }
        return $checks;
    }
}
