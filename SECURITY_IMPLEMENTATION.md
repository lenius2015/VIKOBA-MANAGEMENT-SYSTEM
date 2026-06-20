# Vikoba Security & Monitoring Implementation Guide

## Overview
This guide explains how to integrate the comprehensive security and monitoring features into the Vikoba Management System.

## Database Setup

### Step 1: Run the Migration
Execute the migration SQL file to create all necessary tables and views:

```bash
mysql -u root vikoba_db < database/2026_06_18_security_features.sql
```

This creates:
- `loan_approval_logs` - Detailed loan approval audit trail
- `suspicious_activities` - Detected suspicious behavior
- `module_activity_logs` - Comprehensive activity logging
- `data_audit_trail` - Field-level change tracking
- `online_users` - Real-time session monitoring
- `admin_notifications` - Admin notification center

## Implementation Steps

### Step 1: Include Security Monitoring in Bootstrap

Add to `includes/bootstrap.php`:

```php
require_once __DIR__ . '/../classes/Audit.php';
require_once __DIR__ . '/../classes/SecurityMonitoring.php';
```

### Step 2: Track Login Activity

Already implemented in `Auth.php`. Logins are automatically recorded with:
- User ID and name
- Login/logout times
- IP address
- Browser and OS information
- Device type

### Step 3: Track Member Operations

In `pages/members.php` when adding/updating members:

```php
$security = new SecurityMonitoring();

// When creating a member
$security->trackMemberRegistration($userId, $username, $role, $memberId, $memberName, $memberNo);

// When updating a member field
$security->trackMemberChange($userId, $username, $memberId, $memberName, 'phone', $oldPhone, $newPhone);
```

### Step 4: Track Contribution Recording

In `pages/member_contributions.php` or contribution recording logic:

```php
$security = new SecurityMonitoring();
$security->trackContributionRecorded($userId, $username, $role, $memberId, $memberName, $amount, $method);
```

### Step 5: Track Loan Operations

In `pages/loans.php`:

```php
$security = new SecurityMonitoring();

// When applying for loan
$security->trackLoanApplication($userId, $username, $role, $loanId, $loanNo, $memberId, $memberName, $amount);

// When approving loan
$security->trackLoanApproval($userId, $username, $role, $loanId, $loanNo, $memberId, $memberName, 'pending', 'approved', $comments);

// When rejecting loan
$security->trackLoanRejection($userId, $username, $role, $loanId, $loanNo, $memberId, $memberName, $reason);
```

### Step 6: Track Fine Recording

In `pages/fines.php`:

```php
$security = new SecurityMonitoring();
$security->trackFineIssued($userId, $username, $role, $fineId, $memberId, $memberName, $amount, $reason);
```

### Step 7: Enhanced Session Monitoring

Already handled by `Audit::touchSession()` in the login flow. Ensures real-time tracking of:
- Who is currently online
- Their session activity
- Their browser and device info
- Last activity timestamp

### Step 8: Failed Login Monitoring

Already implemented in `Auth::login()`. Automatically:
- Records failed login attempts
- Tracks IP addresses
- Implements lockout after 5 attempts in 15 minutes

## Using the Audit Class

### Basic Methods

```php
$audit = new Audit();

// Record login
$audit->recordLogin($userId, $username, $role, $sessionId);

// Record logout
$audit->recordLogout($loginActivityId);

// Record failed login
$audit->recordFailedLogin($attemptedUsername);

// Log generic activity
$audit->logActivity($userId, $username, $role, 'module', 'action', 'details');

// Record data change
$audit->recordChange($userId, $username, 'table_name', $recordId, 'field', $oldValue, $newValue);
```

### Advanced Methods

```php
// Record loan approval
$audit->recordLoanApproval($loanId, $loanNo, $memberId, $approvingOfficerId, $approvingOfficerName, 
    $stage, $action, $statusBefore, $statusAfter, $comments);

// Log module activity with entity tracking
$audit->logModuleActivity($userId, $username, $role, 'loans', 'approve', 'loan', $loanId, 'LN-001', 'Loan approved');

// Update online user session
$audit->updateOnlineUser($sessionId, $userId, $username, $role, $browser, $device, $os);

// Record offline
$audit->recordUserOffline($sessionId);

// Detect suspicious activity
$audit->recordSuspiciousActivity($userId, $username, 'brute_force', 'high', 'Details', $ip, $deviceInfo);

// Create alert
$audit->createAlert('critical', 'Alert Title', 'Alert message', 'alert_type', $entityId, $actionUrl);

// Notify admin
$audit->notifyAdmin($adminId, 'notification_type', 'Title', 'Message', 'priority');

// Get statistics
$stats = $audit->getActivityStats(30); // Last 30 days
```

## Security Alerts Dashboard

Access at: `/pages/security_alerts.php`

### Available Tabs

1. **Dashboard** - Overview of key metrics
   - Total logins (30 days)
   - Failed logins
   - Active users
   - Suspicious activities
   - Activity logs count
   - Data changes count

2. **Security Alerts** - System-wide alerts
   - Filter by level (critical, warning, info)
   - Search functionality
   - Export to CSV

3. **Online Users** - Real-time user monitoring
   - Currently active users
   - Session details
   - IP addresses, browser, OS
   - Login times

4. **Failed Logins** - Failed authentication attempts
   - Username/email tried
   - IP address
   - Attempt count
   - Last attempt time

5. **Suspicious Activity** - Detected suspicious patterns
   - Activity type
   - Severity level
   - Details
   - IP address
   - Detection time

6. **Activity Logs** - Comprehensive activity logging
   - User and role
   - Module and action
   - Entity affected
   - Timestamp

7. **Login Activity** - Detailed login/logout history
   - User information
   - Login/logout times
   - Browser and OS
   - IP address
   - Status

8. **Loan Approvals** - Loan approval audit trail
   - Loan reference number
   - Approving officer
   - Approval stage
   - Status changes
   - Approval date

9. **Features** - Documentation of all features

## Notification System

### Creating Admin Notifications

```php
$audit = new Audit();

// Send notification to specific admin
$audit->notifyAdmin($adminId, 'loan_approval', 'Loan LN-001 Approved', 
    'Loan for John Doe has been approved', 'high', 'loan', $loanId);

// Or use SecurityMonitoring to notify all admins
$security = new SecurityMonitoring();
$security->notifyAdmins('loan_approval', 'Loan Approved', 
    'New loan approval requires attention', 'high', 'loan', $loanId);
```

### Notification Types
- `loan_application` - New loan submitted
- `loan_approval` - Loan approved
- `loan_rejection` - Loan rejected
- `member_registration` - New member registered
- `failed_login` - Failed login attempt
- `suspicious_activity` - Suspicious behavior detected
- `backup_status` - Backup completed/failed
- `security_alert` - General security alert

## Detecting Suspicious Activities

The system automatically detects:

1. **Brute Force Attacks**
   - Tracks failed login attempts
   - Triggers alert after 5 failures in 15 minutes
   - Locks account temporarily

2. **Unusual Access Patterns** (Framework ready for GeoIP)
   - Multiple locations in short timeframe
   - Unusual device/browser combinations

3. **Excessive Activities**
   - Unusual number of data modifications
   - Bulk operations

### Manual Suspicious Activity Recording

```php
$audit = new Audit();

$audit->recordSuspiciousActivity(
    $userId, $username, 'unusual_access', 'high',
    'Accessed from new location: [country]',
    $ipAddress, $deviceInfo
);
```

## Audit Trail & Compliance

### Data Modification Tracking

Every important field change is automatically recorded with:
- User who made the change
- Previous value
- New value
- Timestamp
- IP address

### Export Audit Trail

```php
$security = new SecurityMonitoring();
$security->exportAuditTrailToCSV($startDate, $endDate);
// Downloads CSV file with audit trail
```

## Best Practices

1. **Always use SecurityMonitoring class** for tracking operations
   ```php
   $security = new SecurityMonitoring();
   $security->trackMemberChange(...);
   ```

2. **Log all important actions** before they happen
   ```php
   $security->trackLoanApproval(...);
   // Then perform the actual approval
   ```

3. **Notify admins of critical events**
   ```php
   $security->notifyAdmins('critical_event', 'Title', 'Details');
   ```

4. **Review logs regularly**
   - Check security dashboard weekly
   - Review suspicious activities immediately
   - Monitor failed login attempts

5. **Maintain data integrity**
   - Never delete audit records
   - Archive old records after retention period
   - Backup audit tables regularly

## Performance Considerations

1. **Indexes are automatically created** on key columns:
   - `user_id` on activity logs
   - `created_at` on timestamps
   - `ip_address` for IP lookups

2. **Limit queries** when retrieving data:
   - Dashboard uses 30-day rolling window
   - Activity logs limited to 100-200 records per view
   - Cleanup old records periodically

3. **Archive Old Data**
   ```sql
   -- Archive logs older than 1 year
   INSERT INTO audit_archive SELECT * FROM activity_logs 
   WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
   DELETE FROM activity_logs 
   WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
   ```

## Troubleshooting

### Audit table not found error
- Ensure migration SQL has been executed
- Run: `mysql -u root vikoba_db < database/2026_06_18_security_features.sql`

### No records showing in online users
- Check `online_users` table is created
- Verify `Audit::updateOnlineUser()` is called after login
- Check session management in `Auth.php`

### Security alerts not being created
- Verify `system_alerts` table exists
- Ensure `Audit::createAlert()` is called at critical points
- Check for SQL errors in error logs

## Next Steps

1. **Integrate into all modules** - Add tracking calls to member, loan, contribution pages
2. **Configure notification frequency** - Set up email notifications for critical alerts
3. **Implement GeoIP** - Add geographic location detection for logins
4. **Two-factor authentication** - Enhance security with 2FA
5. **Role-based access control** - Restrict audit log access by role
6. **Regular audits** - Schedule weekly security reviews
7. **Backup automation** - Ensure audit tables are backed up regularly

## Support

For issues or questions about the security monitoring system:
1. Check error logs: `/var/log/vikoba/`
2. Review database logs for SQL errors
3. Check Audit class for error handling
4. Contact system administrator

---

**Last Updated:** 2026-06-18  
**Version:** 1.0.0  
**Status:** Complete
