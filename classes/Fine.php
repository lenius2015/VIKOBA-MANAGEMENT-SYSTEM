<?php
// ============================================================
// VIKOBA - Fine Class
// ============================================================

class Fine {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAll(array $filters = []): array {
        $sql = "SELECT f.*, m.name as member_name, m.member_no, u.name as issued_by_name
                FROM fines f JOIN members m ON f.member_id=m.id
                LEFT JOIN users u ON f.issued_by=u.id";
        $where = []; $params = [];
        if (isset($filters['paid'])) { $where[] = "f.paid=?"; $params[] = $filters['paid']; }
        if (!empty($filters['member_id'])) { $where[] = "f.member_id=?"; $params[] = $filters['member_id']; }
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY f.date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function create(array $data): bool {
        $stmt = $this->db->prepare(
            "INSERT INTO fines (member_id, reason, amount, date, issued_by, notes) VALUES (?,?,?,?,?,?)"
        );
        return $stmt->execute([
            $data['member_id'], $data['reason'], $data['amount'],
            $data['date'], $data['issued_by'] ?? null, $data['notes'] ?? ''
        ]);
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT f.*, m.name as member_name FROM fines f JOIN members m ON f.member_id=m.id WHERE f.id=?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function update(int $id, array $data): bool {
        $stmt = $this->db->prepare("UPDATE fines SET member_id=?, reason=?, amount=?, date=?, notes=? WHERE id=?");
        return $stmt->execute([
            $data['member_id'], $data['reason'], $data['amount'],
            $data['date'], $data['notes'] ?? '', $id
        ]);
    }

    public function markPaid(int $id): bool {
        $stmt = $this->db->prepare("UPDATE fines SET paid=1, paid_date=NOW() WHERE id=?");
        return $stmt->execute([$id]);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM fines WHERE id=?");
        return $stmt->execute([$id]);
    }

    public function totalPending(): float {
        $stmt = $this->db->query("SELECT COALESCE(SUM(amount),0) FROM fines WHERE paid=0");
        return (float)$stmt->fetchColumn();
    }

    public function totalCollected(): float {
        $stmt = $this->db->query("SELECT COALESCE(SUM(amount),0) FROM fines WHERE paid=1");
        return (float)$stmt->fetchColumn();
    }
}
