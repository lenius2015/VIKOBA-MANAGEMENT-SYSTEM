<?php
// ============================================================
// VIKOBA - Member Class
// ============================================================

class Member {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAll(string $status = ''): array {
        $sql = "SELECT * FROM members";
        $params = [];
        if ($status) {
            $sql .= " WHERE status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): bool {
        $no = $this->generateMemberNo();
        $stmt = $this->db->prepare(
            "INSERT INTO members (member_no, name, phone, email, address, dob, gender, id_type, id_number, shares, join_date, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        return $stmt->execute([
            $no, $data['name'], $data['phone'], $data['email'] ?? '',
            $data['address'] ?? '', $data['dob'] ?? null, $data['gender'] ?? null,
            $data['id_type'] ?? '', $data['id_number'] ?? '',
            $data['shares'] ?? 1, $data['join_date'], $data['status'] ?? 'active'
        ]);
    }

    public function update(int $id, array $data): bool {
        $stmt = $this->db->prepare(
            "UPDATE members SET name=?, phone=?, email=?, address=?, dob=?, gender=?, shares=?, status=?, updated_at=NOW()
             WHERE id=?"
        );
        return $stmt->execute([
            $data['name'], $data['phone'], $data['email'] ?? '',
            $data['address'] ?? '', $data['dob'] ?? null, $data['gender'] ?? null,
            $data['shares'] ?? 1, $data['status'] ?? 'active', $id
        ]);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM members WHERE id=?");
        return $stmt->execute([$id]);
    }

    public function getTotalContributions(int $memberId): float {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount),0) as total FROM contributions WHERE member_id=?");
        $stmt->execute([$memberId]);
        return (float)$stmt->fetchColumn();
    }

    public function getActiveLoans(int $memberId): array {
        $stmt = $this->db->prepare("SELECT * FROM loans WHERE member_id=? AND status IN ('disbursed')");
        $stmt->execute([$memberId]);
        return $stmt->fetchAll();
    }

    private function generateMemberNo(): string {
        $stmt = $this->db->query("SELECT MAX(CAST(SUBSTRING(member_no,4) AS UNSIGNED)) as max_no FROM members");
        $row = $stmt->fetch();
        $next = ($row['max_no'] ?? 0) + 1;
        return 'VK-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    public function count(string $status = ''): int {
        $sql = "SELECT COUNT(*) FROM members";
        $params = [];
        if ($status) { $sql .= " WHERE status=?"; $params[] = $status; }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
