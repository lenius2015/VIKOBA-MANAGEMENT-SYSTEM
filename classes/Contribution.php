<?php
// ============================================================
// VIKOBA - Contribution Class
// ============================================================

class Contribution {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAll(array $filters = []): array {
        $sql = "SELECT c.*, m.name as member_name, m.member_no, cy.name as cycle_name
                FROM contributions c
                JOIN members m ON c.member_id = m.id
                LEFT JOIN cycles cy ON c.cycle_id = cy.id";
        $where = []; $params = [];
        if (!empty($filters['member_id'])) { $where[] = "c.member_id=?"; $params[] = $filters['member_id']; }
        if (!empty($filters['cycle_id']))  { $where[] = "c.cycle_id=?";  $params[] = $filters['cycle_id']; }
        if (!empty($filters['from']))      { $where[] = "c.date>=?";      $params[] = $filters['from']; }
        if (!empty($filters['to']))        { $where[] = "c.date<=?";      $params[] = $filters['to']; }
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY c.date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function create(array $data): bool {
        $stmt = $this->db->prepare(
            "INSERT INTO contributions (member_id, cycle_id, amount, payment_method, reference, date, recorded_by, notes)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        return $stmt->execute([
            $data['member_id'], $data['cycle_id'] ?? null,
            $data['amount'], $data['payment_method'] ?? 'cash',
            $data['reference'] ?? '', $data['date'],
            $data['recorded_by'] ?? null, $data['notes'] ?? ''
        ]);
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT c.*, m.name as member_name FROM contributions c JOIN members m ON c.member_id=m.id WHERE c.id=?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM contributions WHERE id=?");
        return $stmt->execute([$id]);
    }

    public function totalAmount(array $filters = []): float {
        $records = $this->getAll($filters);
        return array_sum(array_column($records, 'amount'));
    }

    public function getCycles(): array {
        $stmt = $this->db->query("SELECT * FROM cycles ORDER BY start_date DESC");
        return $stmt->fetchAll();
    }

    public function createCycle(array $data): bool {
        $stmt = $this->db->prepare(
            "INSERT INTO cycles (name, start_date, end_date, amount_per_share, status) VALUES (?,?,?,?,?)"
        );
        return $stmt->execute([
            $data['name'], $data['start_date'], $data['end_date'],
            $data['amount_per_share'] ?? 0, $data['status'] ?? 'open'
        ]);
    }
}
