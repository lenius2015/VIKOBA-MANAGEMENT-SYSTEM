<?php
// ============================================================
// VIKOBA - Loan Document Management
// ============================================================

class LoanDocument {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get documents for a loan
     */
    public function getByLoan(int $loanId): array {
        $stmt = $this->db->prepare(
            "SELECT d.*, u.name as verified_by_name
             FROM loan_documents d
             LEFT JOIN users u ON d.verified_by = u.id
             WHERE d.loan_id = ?
             ORDER BY d.created_at DESC"
        );
        $stmt->execute([$loanId]);
        return $stmt->fetchAll();
    }

    /**
     * Get documents for a member
     */
    public function getByMember(int $memberId): array {
        $stmt = $this->db->prepare(
            "SELECT d.*, l.loan_no
             FROM loan_documents d
             JOIN loans l ON d.loan_id = l.id
             WHERE d.member_id = ?
             ORDER BY d.created_at DESC"
        );
        $stmt->execute([$memberId]);
        return $stmt->fetchAll();
    }

    /**
     * Upload a document
     */
    public function upload(int $loanId, int $memberId, string $documentType, array $file): ?int {
        $allowedTypes = ['id_card', 'business_plan', 'collateral_doc', 'guarantor_id', 'income_proof', 'other'];
        if (!in_array($documentType, $allowedTypes)) return null;

        $uploadDir = __DIR__ . '/../uploads/loan_docs/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = 'loan_' . $loanId . '_' . $documentType . '_' . time() . '.' . $extension;
        $filePath = 'uploads/loan_docs/' . $fileName;
        $fullPath = __DIR__ . '/../' . $filePath;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) return null;

        $stmt = $this->db->prepare(
            "INSERT INTO loan_documents (loan_id, member_id, document_type, file_name, file_path, file_size, mime_type)
             VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $loanId, $memberId, $documentType,
            $file['name'], $filePath, $file['size'], $file['type'] ?? ''
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Verify a document
     */
    public function verify(int $id, int $verifiedBy): bool {
        $stmt = $this->db->prepare(
            "UPDATE loan_documents SET verified = 1, verified_by = ?, verified_date = CURDATE() WHERE id = ?"
        );
        return $stmt->execute([$verifiedBy, $id]);
    }

    /**
     * Delete a document
     */
    public function delete(int $id): bool {
        $stmt = $this->db->prepare("SELECT file_path FROM loan_documents WHERE id = ?");
        $stmt->execute([$id]);
        $doc = $stmt->fetch();

        if ($doc) {
            $fullPath = __DIR__ . '/../' . $doc['file_path'];
            if (file_exists($fullPath)) unlink($fullPath);

            $del = $this->db->prepare("DELETE FROM loan_documents WHERE id = ?");
            return $del->execute([$id]);
        }
        return false;
    }

    /**
     * Get document type label
     */
    public static function getTypeLabel(string $type): string {
        return match($type) {
            'id_card'       => 'ID Card',
            'business_plan' => 'Business Plan',
            'collateral_doc'=> 'Collateral Document',
            'guarantor_id'  => 'Guarantor ID',
            'income_proof'  => 'Income Proof',
            default         => 'Other Document',
        };
    }
}