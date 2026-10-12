<?php
declare(strict_types=1);
namespace App\Lib;
use PDO;
use RuntimeException;

/** Named schedules use the existing import catalogue; project identity never changes. */
final class Programmes
{
    public static function catalogue(PDO $db,int $project):array
    {
        $result=[['id'=>0,'name'=>'Original programme','filename'=>null]];
        // Older/minimal installations can still read their original programme.
        $exists=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ? $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='imports'")->fetchColumn()
            : $db->query("SHOW TABLES LIKE 'imports'")->fetchColumn();
        if(!$exists)return $result;
        $q=$db->prepare('SELECT id,filename,mapping_json,created_at FROM imports WHERE project_id=? ORDER BY id');$q->execute([$project]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row){
            $metadata=json_decode((string)($row['mapping_json']??''),true);
            if(($metadata['kind']??null)!=='named_programme'||!is_string($metadata['programme_name']??null))continue;
            $result[]=['id'=>(int)$row['id'],'name'=>$metadata['programme_name'],'filename'=>$row['filename'],'created_at'=>$row['created_at']];
        }
        return $result;
    }
    public static function selected(PDO $db,int $project,mixed $value):array
    {
        if(!is_int($value)&&(!is_string($value)||!preg_match('/^(0|[1-9][0-9]*)$/D',$value)))throw new RuntimeException('Invalid programme');
        foreach(self::catalogue($db,$project)as$item)if($item['id']===(int)$value)return $item;
        throw new RuntimeException('Programme not found in this project');
    }
    public static function condition(PDO $db,int $project,int $programme,string $alias='t'):string
    {
        if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$alias))throw new RuntimeException('Invalid task alias');
        self::selected($db,$project,$programme);$column=$alias.'.source_import_id';
        if($programme>0)return $column.'='.(int)$programme;
        $ids=array_column(array_slice(self::catalogue($db,$project),1),'id');
        return $ids?'('.$column.' IS NULL OR '.$column.' NOT IN ('.implode(',',array_map('intval',$ids)).'))':'1=1';
    }
    public static function request(PDO $db,int $project,string $alias='t'):string
    {
        try{$selected=self::selected($db,$project,$_GET['programme']??'0');return self::condition($db,$project,$selected['id'],$alias);}
        catch(RuntimeException $e){http_response_code(404);header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);exit;}
    }
    public static function forTask(PDO $db,array $task):int
    {
        $id=(int)($task['source_import_id']??0);
        foreach(self::catalogue($db,(int)$task['project_id'])as$item)if($item['id']===$id)return $id;
        return 0;
    }
    public static function create(PDO $db,int $project,string $name,string $filename,int $user):int
    {
        $name=trim($name);if($name===''||strlen($name)>150)throw new RuntimeException('Programme name must contain 1–150 characters');
        $q=$db->prepare('SELECT id FROM projects WHERE id=?');$q->execute([$project]);if(!$q->fetchColumn())throw new RuntimeException('Project not found');
        foreach(self::catalogue($db,$project)as$item)if(strcasecmp($item['name'],$name)===0)throw new RuntimeException('A programme with this name already exists in this project');
        $metadata=json_encode(['kind'=>'named_programme','programme_name'=>$name],JSON_THROW_ON_ERROR);
        $q=$db->prepare('INSERT INTO imports(project_id,filename,mapping_json,created_by,created_at) VALUES(?,?,?,?,?)');
        $q->execute([$project,substr(basename($filename),0,255)?:$name,$metadata,$user,gmdate('Y-m-d H:i:s')]);return(int)$db->lastInsertId();
    }
}
