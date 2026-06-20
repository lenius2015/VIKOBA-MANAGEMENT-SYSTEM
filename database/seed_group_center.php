<?php
// Seed Group Information Center content: group info, leadership, announcements, meetings, events, FAQs.
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $db = Database::getInstance()->getConnection();
    $userId = 1;

    echo "Seeding Group Center data...\n";

    // Group information
    $count = (int)$db->query("SELECT COUNT(*) FROM group_information")->fetchColumn();
    if ($count === 0) {
        $stmt = $db->prepare("INSERT INTO group_information (group_name, registration_number, date_established, mission, vision, objectives, description, updated_by, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW())");
        $stmt->execute([
            'Community Savings Cooperative',
            'CSC-2026-001',
            date('Y-m-d', strtotime('-4 years')),
            'Empower members through collective saving and responsible lending.',
            'A sustainable cooperative that strengthens community financial resilience.',
            '1. Grow member savings\n2. Support small business credit\n3. Promote financial literacy',
            'The Community Savings Cooperative brings members together to build sustainable wealth through savings, loans, and shared support. We focus on transparent governance and community development.',
            $userId
        ]);
        echo "  - Group information inserted.\n";
    } else {
        echo "  - Group information already exists, skipping.\n";
    }

    // Leadership
    $count = (int)$db->query("SELECT COUNT(*) FROM leadership")->fetchColumn();
    if ($count === 0) {
        $leaders = [
            ['name' => 'Amina Mwinyi', 'position' => 'Chairperson', 'contact' => '+255 712 345 678', 'photo' => null, 'order_num' => 1],
            ['name' => 'John Kamali', 'position' => 'Treasurer', 'contact' => '+255 755 123 456', 'photo' => null, 'order_num' => 2],
            ['name' => 'Fatima Hassan', 'position' => 'Secretary', 'contact' => '+255 768 987 654', 'photo' => null, 'order_num' => 3],
        ];
        $stmt = $db->prepare("INSERT INTO leadership (name, position, contact, photo, order_num, created_by, created_at) VALUES (?,?,?,?,?,?,NOW())");
        foreach ($leaders as $leader) {
            $stmt->execute([$leader['name'], $leader['position'], $leader['contact'], $leader['photo'], $leader['order_num'], $userId]);
        }
        echo "  - Leadership entries inserted.\n";
    } else {
        echo "  - Leadership already exists, skipping.\n";
    }

    // Announcements
    $count = (int)$db->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
    if ($count === 0) {
        $stmt = $db->prepare("INSERT INTO announcements (title, body, author_id, publish_at, expires_at, is_published, created_at) VALUES (?,?,?,?,?,?,NOW())");
        $stmt->execute([
            'Welcome to the Group Information Center',
            'This center provides the latest group announcements, upcoming meetings, calendar events, important documents, and FAQs for all members.',
            $userId,
            date('Y-m-d H:i:s', strtotime('-1 day')),
            date('Y-m-d H:i:s', strtotime('+30 days')),
            1
        ]);
        $stmt->execute([
            'Annual General Meeting Scheduled',
            'The Annual General Meeting will be held on the first Saturday of next month. All members are encouraged to attend.',
            $userId,
            date('Y-m-d H:i:s', strtotime('+2 days')),
            date('Y-m-d H:i:s', strtotime('+40 days')),
            1
        ]);
        echo "  - Announcements inserted.\n";
    } else {
        echo "  - Announcements already exist, skipping.\n";
    }

    // Meetings
    $count = (int)$db->query("SELECT COUNT(*) FROM meetings")->fetchColumn();
    if ($count === 0) {
        $stmt = $db->prepare("INSERT INTO meetings (title, meeting_date, location, agenda_file, minutes_file, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
        $stmt->execute([
            'Weekly Planning Session',
            date('Y-m-d H:i:s', strtotime('+3 days 10:00')),
            'Community Hall',
            null,
            null,
            'Discuss upcoming contribution cycle and loan approvals.',
            $userId
        ]);
        echo "  - Meeting scheduled.\n";
    } else {
        echo "  - Meetings already exist, skipping.\n";
    }

    // Calendar events
    $count = (int)$db->query("SELECT COUNT(*) FROM calendar_events")->fetchColumn();
    if ($count === 0) {
        $stmt = $db->prepare("INSERT INTO calendar_events (title, start_datetime, end_datetime, event_type, description, created_by, created_at) VALUES (?,?,?,?,?,?,NOW())");
        $stmt->execute([
            'Member Savings Review',
            date('Y-m-d H:i:s', strtotime('+5 days 09:00')),
            date('Y-m-d H:i:s', strtotime('+5 days 11:00')),
            'Review',
            'A review of member savings contributions and account balances.',
            $userId
        ]);
        $stmt->execute([
            'Loan Application Workshop',
            date('Y-m-d H:i:s', strtotime('+12 days 14:00')),
            date('Y-m-d H:i:s', strtotime('+12 days 16:00')),
            'Workshop',
            'Training session for members on completing loan applications and meeting eligibility requirements.',
            $userId
        ]);
        echo "  - Calendar events inserted.\n";
    } else {
        echo "  - Calendar events already exist, skipping.\n";
    }

    // FAQs
    $count = (int)$db->query("SELECT COUNT(*) FROM faqs")->fetchColumn();
    if ($count === 0) {
        $stmt = $db->prepare("INSERT INTO faqs (question, answer, order_num, created_by, created_at) VALUES (?,?,?,?,NOW())");
        $faqs = [
            ['question' => 'How do I join the group?', 'answer' => 'Complete the membership application form and submit it to the group secretary. Once approved, you can start contributing immediately.', 'order_num' => 1],
            ['question' => 'How do I request a loan?', 'answer' => 'Submit a loan application with required guarantor details. The treasurer will review it during the next committee meeting.', 'order_num' => 2],
            ['question' => 'Where can I find meeting schedules?', 'answer' => 'Upcoming meeting dates are listed in the Group Information Center under Meetings and Calendar Events.', 'order_num' => 3],
        ];
        foreach ($faqs as $faq) {
            $stmt->execute([$faq['question'], $faq['answer'], $faq['order_num'], $userId]);
        }
        echo "  - FAQs inserted.\n";
    } else {
        echo "  - FAQs already exist, skipping.\n";
    }

    echo "Group Center seeding complete.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
