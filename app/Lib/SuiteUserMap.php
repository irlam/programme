<?php
declare(strict_types=1);
namespace App\Lib;

use PDO;
use RuntimeException;
use Throwable;

/** Call only after fresh SuiteGateway validation. Not a standalone login gate. */
final class SuiteUserMap
{
    public function __construct(private readonly PDO $pdo, private readonly array $binding)
    {
        foreach (['instance_id','organization_id','project_id','local_project_id'] as $field) {
            if (!is_int($binding[$field] ?? null) || $binding[$field] < 1) {
                throw new RuntimeException('Invalid instance binding.');
            }
        }
    }

    public function resolve(array $identity): array
    {
        foreach (['instance_id','organization_id','project_id','local_project_id'] as $field) {
            if (($identity[$field] ?? null) !== $this->binding[$field]) {
                throw new RuntimeException('Foreign instance identity.');
            }
        }
        $roles = ['platform_admin'=>'admin','admin'=>'planner','manager'=>'planner',
            'site_manager'=>'planner','user'=>'commenter','contractor'=>'commenter'];
        $role = $roles[$identity['role'] ?? ''] ?? null;
        if (($identity['module_key'] ?? '') !== 'programme' || $role === null
            || ($identity['local_role'] ?? null) !== $role
            || !is_int($identity['user_id'] ?? null) || $identity['user_id'] < 1
            || !is_int($identity['session_expires_at'] ?? null)
            || $identity['session_expires_at'] <= time()
            || !is_string($identity['name'] ?? null)
            || !preg_match('/\A.{1,120}/us', $identity['name'], $name)) {
            throw new RuntimeException('Invalid current Suite identity.');
        }
        if ($this->pdo->inTransaction()) throw new RuntimeException('User mapping needs its own transaction.');
        $this->pdo->beginTransaction();
        try {
            $rows = $this->pdo->query('SELECT id, instance_id, organization_id, project_id, local_project_id FROM suite_instance_binding')->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1 || (int)$rows[0]['id'] !== 1) throw new RuntimeException('Database binding is missing.');
            foreach (['instance_id','organization_id','project_id','local_project_id'] as $field) {
                if ((int)$rows[0][$field] !== $this->binding[$field]) throw new RuntimeException('Database binding mismatch.');
            }
            $project = $this->pdo->prepare('SELECT id FROM projects WHERE id = ?');
            $project->execute([$this->binding['local_project_id']]);
            if ($project->fetchColumn() === false) throw new RuntimeException('Bound project is missing.');
            if ((int)$this->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn() !== 1) {
                throw new RuntimeException('Instance requires one local project.');
            }
            $find = $this->pdo->prepare('SELECT local_user_id FROM suite_user_map WHERE suite_user_id = ?');
            $find->execute([$identity['user_id']]);
            $localId = $find->fetchColumn();
            if ($localId === false) {
                // Never look up or adopt an existing account by email/name.
                $email = 'suite-'.$identity['user_id'].'@instance-'.$this->binding['instance_id'].'.invalid';
                $create = $this->pdo->prepare('INSERT INTO users (name,email,role,password_hash,confirmed) VALUES (?,?,?,?,1)');
                $create->execute([$name[0],$email,$role,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);
                $localId = (int)$this->pdo->lastInsertId();
                $map = $this->pdo->prepare('INSERT INTO suite_user_map (suite_user_id,local_user_id) VALUES (?,?)');
                $map->execute([$identity['user_id'],$localId]);
            }
            $user = $this->pdo->prepare('SELECT id,email FROM users WHERE id = ?');
            $user->execute([$localId]);
            $existing = $user->fetch(PDO::FETCH_ASSOC);
            if (!$existing) throw new RuntimeException('Mapped account is missing.');
            $update = $this->pdo->prepare('UPDATE users SET name = ?, role = ? WHERE id = ?');
            $update->execute([$name[0],$role,$localId]);
            $this->pdo->commit();
            return ['id'=>(int)$localId,'name'=>$name[0],'email'=>$existing['email'],
                'role'=>$role,'suite_user_id'=>$identity['user_id']];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw new RuntimeException('Suite user mapping could not be verified.', 0, $e);
        }
    }
}
