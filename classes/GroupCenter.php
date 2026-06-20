<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Notification.php';

class GroupCenter
{
    protected $db;
    protected $audit;
    protected $notification;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->audit = new Audit();
        $this->notification = new Notification();
    }

    // Group information
    public function getInfo()
    {
        $stmt = $this->db->query("SELECT * FROM group_information ORDER BY id DESC LIMIT 1");
        return $stmt->fetch() ?: null;
    }

    public function updateInfo(array $data, $userId = null)
    {
        $stmt = $this->db->prepare("SELECT id FROM group_information ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row) {
            $sql = "UPDATE group_information SET group_name=?, registration_number=?, date_established=?, mission=?, vision=?, objectives=?, description=?, updated_by=?, updated_at=NOW() WHERE id=?";
            $params = [
                $data['group_name'] ?? null,
                $data['registration_number'] ?? null,
                $data['date_established'] ?? null,
                $data['mission'] ?? null,
                $data['vision'] ?? null,
                $data['objectives'] ?? null,
                $data['description'] ?? null,
                $userId,
                $row['id']
            ];
            $res = $this->db->prepare($sql)->execute($params);
            if ($res) $this->audit->logModuleActivity($userId, $_SESSION['user_name'] ?? null, $_SESSION['user_role'] ?? null, 'group_center', 'update_group_info', 'group_information', $row['id'], $data['group_name'] ?? null, json_encode($data));
            return $res;
        } else {
            $sql = "INSERT INTO group_information (group_name, registration_number, date_established, mission, vision, objectives, description, updated_by) VALUES (?,?,?,?,?,?,?,?)";
            $params = [
                $data['group_name'] ?? null,
                $data['registration_number'] ?? null,
                $data['date_established'] ?? null,
                $data['mission'] ?? null,
                $data['vision'] ?? null,
                $data['objectives'] ?? null,
                $data['description'] ?? null,
                $userId
            ];
            $res = $this->db->prepare($sql)->execute($params);
            if ($res) $this->audit->logModuleActivity($userId, $_SESSION['user_name'] ?? null, $_SESSION['user_role'] ?? null, 'group_center', 'create_group_info', 'group_information', $this->db->lastInsertId(), $data['group_name'] ?? null, json_encode($data));
            return $res;
        }
    }

    // Leadership
    public function getLeadership()
    {
        $stmt = $this->db->query("SELECT * FROM leadership ORDER BY order_num ASC, id ASC");
        return $stmt->fetchAll();
    }

    public function addLeader(array $data, $userId = null)
    {
        $stmt = $this->db->prepare("INSERT INTO leadership (name, position, contact, photo, order_num, created_by) VALUES (?,?,?,?,?,?)");
        $res = $stmt->execute([
            $data['name'] ?? null,
            $data['position'] ?? null,
            $data['contact'] ?? null,
            $data['photo'] ?? null,
            $data['order_num'] ?? 0,
            $userId
        ]);
        if ($res) $this->audit->logModuleActivity($userId, $_SESSION['user_name'] ?? null, $_SESSION['user_role'] ?? null, 'group_center', 'add_leader', 'leadership', $this->db->lastInsertId(), $data['name'] ?? null);
        return $res;
    }

    public function getLeaderById($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM leadership WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        return $stmt->fetch();
    }

    public function updateLeader(array $data, $id, $userId = null)
    {
        $stmt = $this->db->prepare("UPDATE leadership SET name=?, position=?, contact=?, photo=?, order_num=? WHERE id = ?");
        $res = $stmt->execute([
            $data['name'] ?? null,
            $data['position'] ?? null,
            $data['contact'] ?? null,
            $data['photo'] ?? null,
            $data['order_num'] ?? 0,
            (int)$id
        ]);
        if ($res) {
            $this->audit->logModuleActivity($userId, $_SESSION['user_name'] ?? null, $_SESSION['user_role'] ?? null, 'group_center', 'update_leader', 'leadership', $id, $data['name'] ?? null);
        }
        return $res;
    }

    public function deleteLeader($id, $userId = null)
    {
        $leader = $this->getLeaderById($id);
        $stmt = $this->db->prepare("DELETE FROM leadership WHERE id = ?");
        $res = $stmt->execute([(int)$id]);
        if ($res) {
            $this->audit->logModuleActivity($userId, $_SESSION['user_name'] ?? null, $_SESSION['user_role'] ?? null, 'group_center', 'delete_leader', 'leadership', $id, $leader['name'] ?? null);
        }
        return $res;
    }

    // Announcements
    public function getAnnouncements($limit = 20)
    {
        $stmt = $this->db->prepare("SELECT * FROM announcements WHERE is_published = 1 AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY publish_at DESC LIMIT ?");
        $stmt->execute([(int)$limit]);
        return $stmt->fetchAll();
    }

    public function getAllAnnouncements($limit = 50)
    {
        $stmt = $this->db->prepare("SELECT * FROM announcements ORDER BY publish_at DESC LIMIT ?");
        $stmt->execute([(int)$limit]);
        return $stmt->fetchAll();
    }

    public function getAnnouncementById($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM announcements WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        return $stmt->fetch();
    }

    public function updateAnnouncement(array $data, $id, $userId = null)
    {
        $stmt = $this->db->prepare("UPDATE announcements SET title=?, body=?, publish_at=?, expires_at=?, is_published=?, updated_at=NOW() WHERE id = ?");
        $res = $stmt->execute([
            $data['title'] ?? null,
            $data['body'] ?? null,
            $data['publish_at'] ?? date('Y-m-d H:i:s'),
            $data['expires_at'] ?? null,
            isset($data['is_published']) ? (int)$data['is_published'] : 1,
            (int)$id
        ]);
        if ($res) {
            $this->audit->logModuleActivity($userId, $_SESSION['user_name'] ?? null, $_SESSION['user_role'] ?? null, 'group_center', 'update_announcement', 'announcements', $id, $data['title'] ?? null);
        }
        return $res;
    }

    public function createAnnouncement(array $data, $userId = null)
    {
        $stmt = $this->db->prepare("INSERT INTO announcements (title, body, author_id, publish_at, expires_at, is_published, created_at) VALUES (?,?,?,?,?,?,NOW())");
        $res = $stmt->execute([
            $data['title'] ?? null,
            $data['body'] ?? null,
            $userId,
            $data['publish_at'] ?? date('Y-m-d H:i:s'),
            $data['expires_at'] ?? null,
            isset($data['is_published']) ? (int)$data['is_published'] : 1
        ]);
        if ($res) {
            $id = $this->db->lastInsertId();
            $this->audit->logModuleActivity($userId, $_SESSION['user_name'] ?? null, $_SESSION['user_role'] ?? null, 'group_center', 'create_announcement', 'announcements', $id, $data['title'] ?? null);
            try {
                $this->notification->notifyAll('announcement', $data['title'] ?? 'Announcement', substr($data['body'] ?? '', 0, 250), APP_URL . '/pages/group_info_center.php');
            } catch (Throwable $e) {
                // ignore missing notification class or delivery issues
            }
        }
        return $res;
    }

    public function deleteAnnouncement($id, $userId = null)
    {
        $stmt = $this->db->prepare("DELETE FROM announcements WHERE id = ?");
        $res = $stmt->execute([(int)$id]);
        if ($res) $this->audit->logModuleActivity($userId, $_SESSION['user_name'] ?? null, $_SESSION['user_role'] ?? null, 'group_center', 'delete_announcement', 'announcements', $id, null);
        return $res;
    }

    // Meetings
    public function getUpcomingMeetings($limit = 10)
    {
        $stmt = $this->db->prepare("SELECT * FROM meetings WHERE meeting_date >= NOW() ORDER BY meeting_date ASC LIMIT ?");
        $stmt->execute([(int)$limit]);
        return $stmt->fetchAll();
    }

    public function getPastMeetings($limit = 10)
    {
        $stmt = $this->db->prepare("SELECT * FROM meetings WHERE meeting_date < NOW() ORDER BY meeting_date DESC LIMIT ?");
        $stmt->execute([(int)$limit]);
        return $stmt->fetchAll();
    }

    public function scheduleMeeting(array $data, $userId = null)
    {
        $stmt = $this->db->prepare("INSERT INTO meetings (title, meeting_date, location, agenda_file, minutes_file, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
        $res = $stmt->execute([
            $data['title'] ?? null,
            $data['meeting_date'] ?? null,
            $data['location'] ?? null,
            $data['agenda_file'] ?? null,
            $data['minutes_file'] ?? null,
            $data['notes'] ?? null,
            $userId
        ]);
        if ($res) {
            $meetingId = $this->db->lastInsertId();
            $this->audit->logModuleActivity($userId, $_SESSION['user_name'] ?? null, $_SESSION['user_role'] ?? null, 'group_center', 'schedule_meeting', 'meetings', $meetingId, $data['title'] ?? null);
            try {
                $this->notification->notifyAll('meeting', 'New meeting scheduled', substr(($data['notes'] ?? $data['title'] ?? ''), 0, 250), APP_URL . '/pages/group_info_center.php');
            } catch (Throwable $e) {}
        }
        return $res;
    }

    // Calendar
    public function getCalendarEvents($limit = 20)
    {
        $stmt = $this->db->prepare("SELECT * FROM calendar_events ORDER BY start_datetime ASC LIMIT ?");
        $stmt->execute([(int)$limit]);
        return $stmt->fetchAll();
    }

    public function addCalendarEvent(array $data, $userId = null)
    {
        $stmt = $this->db->prepare("INSERT INTO calendar_events (title, start_datetime, end_datetime, event_type, description, created_by, created_at) VALUES (?,?,?,?,?,?,NOW())");
        $res = $stmt->execute([
            $data['title'] ?? null,
            $data['start_datetime'] ?? null,
            $data['end_datetime'] ?? null,
            $data['event_type'] ?? null,
            $data['description'] ?? null,
            $userId
        ]);
        if ($res) {
            $eventId = $this->db->lastInsertId();
            $this->audit->logModuleActivity($userId, $_SESSION['user_name'] ?? null, $_SESSION['user_role'] ?? null, 'group_center', 'add_calendar_event', 'calendar_events', $eventId, $data['title'] ?? null);
        }
        return $res;
    }

    // Documents
    public function addDocument(array $data, $userId = null)
    {
        $stmt = $this->db->prepare("INSERT INTO documents (title, filename, file_type, uploaded_by, uploaded_at, permission_level) VALUES (?,?,?,?,NOW(),?)");
        $res = $stmt->execute([
            $data['title'] ?? null,
            $data['filename'] ?? null,
            $data['file_type'] ?? null,
            $userId,
            $data['permission_level'] ?? 'public'
        ]);
        if ($res) $this->audit->logModuleActivity($userId, $_SESSION['user_name'] ?? null, $_SESSION['user_role'] ?? null, 'group_center', 'add_document', 'documents', $this->db->lastInsertId(), $data['title'] ?? null);
        return $res;
    }

    public function getDocuments($visibility = 'public')
    {
        // return documents based on permission_level
        if ($visibility === 'public') {
            $stmt = $this->db->prepare("SELECT * FROM documents WHERE permission_level IN ('public','members') ORDER BY uploaded_at DESC");
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare("SELECT * FROM documents ORDER BY uploaded_at DESC");
            $stmt->execute();
        }
        return $stmt->fetchAll();
    }

    // FAQ
    public function getFaqs()
    {
        $stmt = $this->db->query("SELECT * FROM faqs ORDER BY order_num ASC, id ASC");
        return $stmt->fetchAll();
    }

    public function addFaq(array $data, $userId = null)
    {
        $stmt = $this->db->prepare("INSERT INTO faqs (question, answer, order_num, created_by, created_at) VALUES (?,?,?,?,NOW())");
        $res = $stmt->execute([
            $data['question'] ?? null,
            $data['answer'] ?? null,
            $data['order_num'] ?? 0,
            $userId
        ]);
        if ($res) $this->audit->logModuleActivity($userId, $_SESSION['user_name'] ?? null, $_SESSION['user_role'] ?? null, 'group_center', 'add_faq', 'faqs', $this->db->lastInsertId(), $data['question'] ?? null);
        return $res;
    }

}
