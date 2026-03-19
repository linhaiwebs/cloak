<?php
declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;

class DatabaseHelper
{
    private PDO $pdo;
    private string $dbPath;

    public function __construct(string $dataDir)
    {
        $this->dbPath = $dataDir . '/tracking.db';
        $this->connect();
        $this->initializeDatabase();
    }

    private function connect(): void
    {
        try {
            $this->pdo = new PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    private function initializeDatabase(): void
    {
        $sql = <<<SQL
        CREATE TABLE IF NOT EXISTS clicks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            click_id TEXT UNIQUE NOT NULL,
            flow_id INTEGER,
            flow_domain TEXT,
            date_created TEXT,
            time_created INTEGER,
            country_code TEXT,
            ip_address TEXT,
            isp TEXT,
            referer TEXT,
            user_agent TEXT,
            device TEXT,
            brand TEXT,
            os TEXT,
            browser TEXT,
            language TEXT,
            timezone TEXT,
            connection_type TEXT,
            filter_type TEXT,
            filter_page TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE INDEX IF NOT EXISTS idx_date_created ON clicks(date_created);
        CREATE INDEX IF NOT EXISTS idx_country_code ON clicks(country_code);
        CREATE INDEX IF NOT EXISTS idx_flow_id ON clicks(flow_id);
        CREATE INDEX IF NOT EXISTS idx_filter_page ON clicks(filter_page);
        CREATE INDEX IF NOT EXISTS idx_flow_domain ON clicks(flow_domain);
        SQL;

        try {
            $this->pdo->exec($sql);

            $columns = $this->pdo->query("PRAGMA table_info(clicks)")->fetchAll(PDO::FETCH_ASSOC);
            $hasFlowDomain = false;
            foreach ($columns as $column) {
                if ($column['name'] === 'flow_domain') {
                    $hasFlowDomain = true;
                    break;
                }
            }

            if (!$hasFlowDomain) {
                $this->pdo->exec("ALTER TABLE clicks ADD COLUMN flow_domain TEXT");
            }
        } catch (PDOException $e) {
            throw new \RuntimeException('Database initialization failed: ' . $e->getMessage());
        }
    }

    public function insertClicks(array $clicks): int
    {
        $sql = <<<SQL
        INSERT OR IGNORE INTO clicks (
            click_id, flow_id, flow_domain, date_created, time_created, country_code,
            ip_address, isp, referer, user_agent, device, brand, os,
            browser, filter_type, filter_page
        ) VALUES (
            :click_id, :flow_id, :flow_domain, :date_created, :time_created, :country_code,
            :ip_address, :isp, :referer, :user_agent, :device, :brand, :os,
            :browser, :filter_type, :filter_page
        )
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $inserted = 0;

        foreach ($clicks as $click) {
            try {
                $stmt->execute([
                    ':click_id' => $click['click_id'] ?? '',
                    ':flow_id' => $click['flow_id'] ?? null,
                    ':flow_domain' => $click['flow_domain'] ?? null,
                    ':date_created' => $click['date_created'] ?? '',
                    ':time_created' => $click['time_created'] ?? null,
                    ':country_code' => $click['country_code'] ?? '',
                    ':ip_address' => $click['ip_address'] ?? '',
                    ':isp' => $click['isp'] ?? '',
                    ':referer' => $click['referer'] ?? '',
                    ':user_agent' => $click['user_agent'] ?? '',
                    ':device' => $click['device'] ?? '',
                    ':brand' => $click['brand'] ?? '',
                    ':os' => $click['os'] ?? '',
                    ':browser' => $click['browser'] ?? '',
                    ':filter_type' => $click['filter_type'] ?? '',
                    ':filter_page' => $click['filter_page'] ?? '',
                ]);
                $inserted++;
            } catch (PDOException $e) {
                continue;
            }
        }

        return $inserted;
    }

    public function getClicks(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'date_created >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'date_created <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['countries']) && is_array($filters['countries'])) {
            $placeholders = [];
            foreach ($filters['countries'] as $i => $country) {
                $key = ':country_' . $i;
                $placeholders[] = $key;
                $params[$key] = $country;
            }
            $where[] = 'country_code IN (' . implode(',', $placeholders) . ')';
        }

        if (!empty($filters['flow_ids']) && is_array($filters['flow_ids'])) {
            $placeholders = [];
            foreach ($filters['flow_ids'] as $i => $flowId) {
                $key = ':flow_' . $i;
                $placeholders[] = $key;
                $params[$key] = $flowId;
            }
            $where[] = 'flow_id IN (' . implode(',', $placeholders) . ')';
        }

        if (!empty($filters['devices']) && is_array($filters['devices'])) {
            $placeholders = [];
            foreach ($filters['devices'] as $i => $device) {
                $key = ':device_' . $i;
                $placeholders[] = $key;
                $params[$key] = $device;
            }
            $where[] = 'device IN (' . implode(',', $placeholders) . ')';
        }

        if (!empty($filters['os']) && is_array($filters['os'])) {
            $placeholders = [];
            foreach ($filters['os'] as $i => $os) {
                $key = ':os_' . $i;
                $placeholders[] = $key;
                $params[$key] = $os;
            }
            $where[] = 'os IN (' . implode(',', $placeholders) . ')';
        }

        if (!empty($filters['browsers']) && is_array($filters['browsers'])) {
            $placeholders = [];
            foreach ($filters['browsers'] as $i => $browser) {
                $key = ':browser_' . $i;
                $placeholders[] = $key;
                $params[$key] = $browser;
            }
            $where[] = 'browser IN (' . implode(',', $placeholders) . ')';
        }

        if (!empty($filters['filter_types']) && is_array($filters['filter_types'])) {
            $placeholders = [];
            foreach ($filters['filter_types'] as $i => $type) {
                $key = ':filter_type_' . $i;
                $placeholders[] = $key;
                $params[$key] = $type;
            }
            $where[] = 'filter_type IN (' . implode(',', $placeholders) . ')';
        }

        if (!empty($filters['page_type'])) {
            $where[] = 'filter_page = :page_type';
            $params[':page_type'] = $filters['page_type'];
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) as total FROM clicks $whereClause";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetch()['total'];

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM clicks $whereClause ORDER BY time_created DESC, id DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $data = $stmt->fetchAll();

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage)
        ];
    }

    public function getFilterOptions(?string $domain = null): array
    {
        $countries = $this->pdo->query("SELECT DISTINCT country_code FROM clicks WHERE country_code != '' ORDER BY country_code")->fetchAll(PDO::FETCH_COLUMN);
        $devices = $this->pdo->query("SELECT DISTINCT device FROM clicks WHERE device != '' ORDER BY device")->fetchAll(PDO::FETCH_COLUMN);
        $os = $this->pdo->query("SELECT DISTINCT os FROM clicks WHERE os != '' ORDER BY os")->fetchAll(PDO::FETCH_COLUMN);
        $browsers = $this->pdo->query("SELECT DISTINCT browser FROM clicks WHERE browser != '' ORDER BY browser")->fetchAll(PDO::FETCH_COLUMN);
        $filterTypes = $this->pdo->query("SELECT DISTINCT filter_type FROM clicks WHERE filter_type != '' ORDER BY filter_type")->fetchAll(PDO::FETCH_COLUMN);

        $flowsSql = "SELECT DISTINCT flow_id, flow_domain FROM clicks WHERE flow_id IS NOT NULL";
        if ($domain) {
            $flowsSql .= " AND flow_domain = :domain";
        }
        $flowsSql .= " ORDER BY flow_id";

        $stmt = $this->pdo->prepare($flowsSql);
        if ($domain) {
            $stmt->bindValue(':domain', $domain, PDO::PARAM_STR);
        }
        $stmt->execute();
        $flows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'countries' => $countries,
            'devices' => $devices,
            'os' => $os,
            'browsers' => $browsers,
            'filter_types' => $filterTypes,
            'page_types' => ['white', 'offer'],
            'flows' => $flows
        ];
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }
}
