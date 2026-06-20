<?php
// ============================================================
// VIKOBA - Group Class
// ============================================================

class Group {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create(array $data): bool {
        $stmt = $this->db->prepare("INSERT INTO `groups` (name, slug, description, created_by, status) VALUES (?,?,?,?,?)");
        return $stmt->execute([
            $data['name'], $data['slug'] ?? null, $data['description'] ?? null,
            $data['created_by'] ?? null, $data['status'] ?? 'active'
        ]);
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `groups` WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getAll(): array {
        $stmt = $this->db->query("SELECT * FROM `groups` ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    public function getByMemberId(int $memberId): array {
        $stmt = $this->db->prepare("SELECT g.* FROM `groups` g JOIN group_members gm ON gm.group_id = g.id WHERE gm.member_id = ?");
        $stmt->execute([$memberId]);
        return $stmt->fetchAll();
    }

    public function getMembers(int $groupId): array {
        $stmt = $this->db->prepare("SELECT m.* , gm.role, gm.joined_at FROM group_members gm JOIN members m ON gm.member_id = m.id WHERE gm.group_id = ? ORDER BY m.name ASC");
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }

    public function addMember(int $groupId, int $memberId, string $role = 'member'): bool {
        $stmt = $this->db->prepare("INSERT IGNORE INTO group_members (group_id, member_id, role) VALUES (?,?,?)");
        return $stmt->execute([$groupId, $memberId, $role]);
    }

    public function removeMember(int $groupId, int $memberId): bool {
        $stmt = $this->db->prepare("DELETE FROM group_members WHERE group_id = ? AND member_id = ?");
        return $stmt->execute([$groupId, $memberId]);
    }

    public function totalContributions(int $groupId): float {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(c.amount),0) FROM contributions c JOIN group_members gm ON gm.member_id = c.member_id WHERE gm.group_id = ?"
        );
        $stmt->execute([$groupId]);
        return (float)$stmt->fetchColumn();
    }

    public function totalLoansDisbursed(int $groupId): float {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(l.amount),0) FROM loans l JOIN group_members gm ON gm.member_id = l.member_id WHERE gm.group_id = ? AND l.status = 'disbursed'"
        );
        $stmt->execute([$groupId]);
        return (float)$stmt->fetchColumn();
    }

    public function totalFines(int $groupId): float {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(f.amount),0) FROM fines f JOIN group_members gm ON gm.member_id = f.member_id WHERE gm.group_id = ?"
        );
        $stmt->execute([$groupId]);
        return (float)$stmt->fetchColumn();
    }

    public function summary(int $groupId): array {
        $group = $this->getById($groupId);
        $members = $this->getMembers($groupId);
        return [
            'group' => $group,
            'members' => $members,
            'member_count' => count($members),
            'total_contributions' => $this->totalContributions($groupId),
            'total_loans_disbursed' => $this->totalLoansDisbursed($groupId),
            'total_fines' => $this->totalFines($groupId),
        ];
    }
}
