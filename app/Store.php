<?php
declare(strict_types=1);
namespace Pup;

use PDO;

final class Store {
    public PDO $db;
    public string $driver;

    public function __construct(array $c) {
        $this->db = new PDO($c['dsn'], $c['db_user'] ?? null, $c['db_password'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($this->driver === 'sqlite') {
            $this->db->exec('PRAGMA journal_mode=WAL');
            $this->db->exec('PRAGMA busy_timeout=5000');
        }
    }

    public function migrate(): void {
        if ($this->driver==='sqlite' && version_compare((string)$this->db->query('SELECT sqlite_version()')->fetchColumn(),'3.24.0','<'))
            throw new \RuntimeException('SQLite 3.24+ is required. Select MySQL/MariaDB on older hosts.');
        $suffix = $this->driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $this->db->exec('CREATE TABLE IF NOT EXISTS records (
            bucket VARCHAR(32) NOT NULL, id CHAR(64) NOT NULL, ref TEXT NOT NULL,
            data ' . ($this->driver === 'mysql' ? 'LONGTEXT' : 'TEXT') . ' NOT NULL,
            created BIGINT NOT NULL, updated BIGINT NOT NULL, PRIMARY KEY(bucket,id))' . $suffix);
        $this->db->exec('CREATE TABLE IF NOT EXISTS jobs (
            id CHAR(32) PRIMARY KEY, payload TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
            due BIGINT NOT NULL, state VARCHAR(16) NOT NULL, error TEXT NOT NULL,
            created BIGINT NOT NULL, updated BIGINT NOT NULL, priority INTEGER NOT NULL DEFAULT 10)' . $suffix);
        $this->db->exec('CREATE TABLE IF NOT EXISTS discovery_queue (
            id CHAR(64) PRIMARY KEY, kind VARCHAR(24) NOT NULL, ref TEXT NOT NULL,
            domain VARCHAR(253) NOT NULL, due BIGINT NOT NULL, updated BIGINT NOT NULL)' . $suffix);
        try { $this->db->exec('CREATE INDEX discovery_due ON discovery_queue(due,updated,id)'); } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'already exists') && !str_contains($e->getMessage(), 'Duplicate key name')) throw $e;
        }
        try { $this->db->exec('CREATE INDEX records_chrono ON records(bucket,created,id)'); } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'already exists') && !str_contains($e->getMessage(), 'Duplicate key name')) throw $e;
        }
        try { $this->db->exec('CREATE INDEX jobs_due ON jobs(state,due)'); } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'already exists') && !str_contains($e->getMessage(), 'Duplicate key name')) throw $e;
        }
        // Existing queues keep every payload and retry state during an upgrade.
        $columns=$this->driver==='mysql'?$this->query('SHOW COLUMNS FROM jobs')->fetchAll():$this->query('PRAGMA table_info(jobs)')->fetchAll();
        if(!in_array('priority',array_column($columns,$this->driver==='mysql'?'Field':'name'),true))$this->db->exec('ALTER TABLE jobs ADD COLUMN priority INTEGER NOT NULL DEFAULT 10');
        if(!$this->get('meta','federation_schema_0_5_2'))$this->tx(function(){
            foreach($this->query("SELECT id,payload FROM jobs WHERE state='pending'")->fetchAll() as $job){
                $payload=json_decode($job['payload'],true,64,JSON_THROW_ON_ERROR);
                $this->query('UPDATE jobs SET priority=? WHERE id=?',[Federation::priority($payload),$job['id']]);
            }
            foreach(['comments','timeline','remote','saved','discovery_posts'] as $bucket)foreach($this->each($bucket) as $post){
                if(!empty($post['inReplyTo'])&&!Federation::hasImage($post))$this->remove($bucket,$post['id']);
            }
            $this->put('meta','federation_schema_0_5_2',['version'=>'0.5.2']);
        });
        if(!$this->get('meta','cache_schema_0_5_1'))$this->tx(function(){
            // Drop only disposable pre-verification display caches when upgrading.
            foreach(['profiles','cache','remote','discovery_posts'] as $bucket)$this->query('DELETE FROM records WHERE bucket=?',[$bucket]);
            $this->put('meta','cache_schema_0_5_1',['version'=>'0.5.1']);
        });
    }

    public function query(string $sql, array $args = []): \PDOStatement {
        $q = $this->db->prepare($sql); $q->execute($args); return $q;
    }

    public function get(string $bucket, string $ref): ?array {
        $row = $this->query('SELECT data FROM records WHERE bucket=? AND id=?', [$bucket, hash('sha256', $ref)])->fetch();
        return $row ? json_decode($row['data'], true, 64, JSON_THROW_ON_ERROR) : null;
    }

    public function put(string $bucket, string $ref, array $value, ?int $created = null): void {
        $sql = 'INSERT INTO records(bucket,id,ref,data,created,updated) VALUES(?,?,?,?,?,?)';
        $sql .= $this->driver === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE data=VALUES(data),updated=VALUES(updated)'
            : ' ON CONFLICT(bucket,id) DO UPDATE SET data=excluded.data,updated=excluded.updated';
        $this->query($sql, [$bucket,hash('sha256',$ref),$ref,App::json($value),$created ?? time(),time()]);
    }

    public function remove(string $bucket, string $ref): void {
        $this->query('DELETE FROM records WHERE bucket=? AND id=?', [$bucket,hash('sha256',$ref)]);
    }

    public function add(string $bucket,string $ref,array $value): bool {
        $sql='INSERT INTO records(bucket,id,ref,data,created,updated) VALUES(?,?,?,?,?,?)';
        $sql.=$this->driver==='mysql'?' ON DUPLICATE KEY UPDATE id=id':' ON CONFLICT(bucket,id) DO NOTHING';
        return $this->query($sql,[$bucket,hash('sha256',$ref),$ref,App::json($value),time(),time()])->rowCount()===1;
    }

    public function page(string $bucket, int $offset = 0, int $limit = 24): array {
        $offset = max(0,$offset); $limit = min(500,max(1,$limit));
        $rows = $this->query("SELECT data FROM records WHERE bucket=? ORDER BY created DESC,id DESC LIMIT $limit OFFSET $offset",[$bucket])->fetchAll();
        return array_map(static fn($r) => json_decode($r['data'],true,64,JSON_THROW_ON_ERROR),$rows);
    }

    public function each(string $bucket): \Generator {
        // Keyset pages remain correct when the caller deletes items while iterating.
        $last=null;
        while(true) {
            $where=$last?' AND (created<? OR (created=? AND id<?))':'';
            $args=$last?[$bucket,$last['created'],$last['created'],$last['id']]:[$bucket];
            $rows=$this->query('SELECT data,created,id FROM records WHERE bucket=?'.$where.' ORDER BY created DESC,id DESC LIMIT 200',$args)->fetchAll();
            if(!$rows)break;
            $last=end($rows);
            foreach ($rows as $row) yield json_decode($row['data'],true,64,JSON_THROW_ON_ERROR);
            if(count($rows)<200)break;
        }
    }

    public function count(string $bucket): int {
        return (int)$this->query('SELECT COUNT(*) FROM records WHERE bucket=?',[$bucket])->fetchColumn();
    }

    public function tx(callable $fn): mixed {
        if ($this->db->inTransaction()) return $fn();
        $this->db->beginTransaction();
        try { $v=$fn(); $this->db->commit(); return $v; }
        catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }
}
