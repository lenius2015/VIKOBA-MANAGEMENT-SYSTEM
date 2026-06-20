# Vikoba Security Dashboard - Quick Reference Guide

## 🎯 Access the Dashboard

**URL:** `http://localhost/vikoba/pages/security_alerts.php`  
**Required Role:** Admin only  
**Frequency:** Check weekly (daily for critical systems)

---

## 📊 Dashboard Sections

### 1. Statistics Overview
Located at the top of the dashboard showing:

| Metric | What It Means | Action If High |
|--------|--------------|-----------------|
| Total Logins (30 days) | Normal system usage | Normal indicator |
| Failed Logins (30 days) | Security concerns | Investigate users with multiple attempts |
| Active Users (Now) | Current activity | Use for concurrent user planning |
| Suspicious Activities | Detected threats | Review immediately |
| Activity Logs (30 days) | User action volume | Normal indicator |
| Data Changes (30 days) | Modifications made | Review critical field changes |
| Unread System Alerts | Pending attention | Action required |
| Critical Alerts | Urgent issues | Action required immediately |

---

## 🔍 Understanding Each Tab

### Tab: Dashboard
**Purpose:** Quick overview and metrics

**What to look for:**
- Number of unread alerts (should be 0)
- Critical alert count (should be 0)
- Active user count (normal range for your organization)
- Suspicious activity count (should be minimal)

**Actions:**
- Click "Manage Online Users" to see who's online
- Click "Check Suspicious Activity" for security events

---

### Tab: Security Alerts
**Purpose:** View all system-wide security alerts

**Color Coding:**
- 🔴 **Red (Critical)** - Immediate attention required
- 🟠 **Orange (Warning)** - Should be investigated
- 🔵 **Blue (Info)** - Informational, no action needed

**Filtering Options:**
- Filter by severity level
- Search by title or message

**Actions:**
- Mark alerts as "Read" after reviewing
- Export to CSV for records

**Common Alerts You May See:**
- "Brute Force Attack Detected" - Failed login attempts
- "Account Locked" - Account locked due to failed logins
- "Failed Login Attempt" - User tried wrong password
- "New Member Registered" - Informational
- "Loan Application Received" - Informational

---

### Tab: Online Users
**Purpose:** Monitor who is currently using the system

**Columns:**
| Column | Meaning |
|--------|---------|
| Username | User's login name |
| Role | admin/treasurer/member |
| IP Address | Their internet address |
| Browser | Chrome/Firefox/Safari/Edge |
| Device | Desktop/Mobile/Tablet |
| OS | Windows/Mac/Linux/iOS/Android |
| Login Time | When they logged in |
| Last Activity | Last action timestamp |
| Status | active/idle/offline |

**Normal Behavior:**
- Admins and treasurer online during business hours
- Members online accessing their accounts
- Status changes from active to idle when inactive

**Actions to Take:**
- If someone suspicious is online, note the IP address
- Verify device/browser combinations match known users
- Check unusual login times (e.g., 3 AM)

**Red Flags:**
- Users logged in 24/7 (likely system error)
- Same user from multiple devices simultaneously (check if legitimate)
- Sessions lasting many hours without activity (should auto-logout)

---

### Tab: Failed Logins
**Purpose:** Track unsuccessful login attempts

**Columns:**
| Column | Meaning |
|--------|---------|
| Attempted Username | Who tried to log in |
| IP Address | Where they tried from |
| Attempt Count | Number of failed tries |
| Last Attempt | Most recent failed try |

**What's Normal:**
- User forgot password and tried 2-3 times, then reset it
- One or two failed attempts per user per week
- Attempts from expected IP addresses

**Red Flags:**
- Same username with 5+ attempts (account lockout triggered)
- Attempts from unusual IP addresses/locations
- Multiple different usernames from same IP (possible attack)
- Many attempts in short time window

**Actions to Take:**
- Click on user with 5+ attempts to reset their account
- Check IP address location (if unusual, investigate)
- Contact user if they report being locked out
- Document repeated attacks

**System Behavior:**
- Automatic lockout after 5 failed attempts in 15 minutes
- Account automatically unlocks after 15 minutes
- Longer lockout can be enabled for critical accounts

---

### Tab: Suspicious Activity
**Purpose:** View automatically detected suspicious behaviors

**Activity Types:**
| Type | Severity | Action |
|------|----------|--------|
| brute_force | HIGH | Review failed login tab |
| unusual_location | MEDIUM | Verify with user |
| new_device | LOW | May be expected |
| unusual_time | MEDIUM | Check with user |
| excessive_attempts | HIGH | Possible attack |

**Severity Levels:**
- 🔴 **Critical** - Likely attack, investigate immediately
- 🟠 **High** - Probable threat, review and act
- 🟡 **Medium** - Unusual but may be legitimate
- 🟢 **Low** - Minor concern, document

**Actions to Take:**
- For CRITICAL: Disable account, contact user, check system
- For HIGH: Verify activity with user, monitor account
- For MEDIUM: Ask user to confirm unusual activity
- For LOW: Document and monitor

---

### Tab: Activity Logs
**Purpose:** See all important actions users performed

**Columns:**
| Column | Meaning |
|--------|---------|
| User | Who performed the action |
| Module | System area (loans, members, etc) |
| Action | What they did (create, update, approve, etc) |
| Entity Type | What they worked on |
| IP Address | Where they accessed from |
| Timestamp | When they did it |

**Modules Include:**
- members - Member management
- loans - Loan processing
- contributions - Contribution recording
- fines - Fine management
- users - User management
- security - Security operations

**Actions Include:**
- create - Added new record
- update - Modified record
- delete - Removed record
- approve - Approved something
- reject - Rejected something
- export - Downloaded/exported data

**What to Monitor:**
- Users updating sensitive fields (salaries, rates, etc)
- After-hours access
- Bulk operations
- Deletions (especially audit data)

**Export Function:**
- Click dates to export specific period
- Useful for compliance reports
- Retains history for legal purposes

---

### Tab: Login Activity
**Purpose:** Detailed login/logout history for accountability

**Columns:**
| Column | Meaning |
|--------|---------|
| User | Who logged in |
| Role | Their position |
| Login Time | When they entered |
| Logout Time | When they left |
| Browser | Their browser |
| OS | Their operating system |
| IP Address | Their IP address |
| Status | success/failed |

**What This Shows:**
- Work hours compliance
- Remote access patterns
- Device usage trends
- Potential security issues

**Analytics:**
- How long users stay logged in
- Peak usage hours
- Mobile vs desktop usage
- Geographic access patterns

**Actions:**
- Verify unusual login times
- Check devices for unexpected access
- Monitor after-hours access
- Identify inactive logins

---

### Tab: Loan Approvals
**Purpose:** Complete audit trail of all loan approval decisions

**Columns:**
| Column | Meaning |
|--------|---------|
| Loan No | Unique loan reference |
| Officer | Who approved it |
| Stage | Review/Approval phase |
| Action | Approve/Reject/Review |
| Status | Current status |
| Date | When it happened |

**What This Enables:**
- Trace every decision
- Verify authorization
- Find approval bottlenecks
- Audit compliance

**Questions You Can Answer:**
- Who approved this loan? (Officer column)
- When was it approved? (Date column)
- What stage was it at? (Stage column)
- Was it reviewed before approval? (History)
- Why was it rejected? (Comments)

**Red Flags:**
- Loans approved without review stage
- Approvals without required authorization
- Unusual approval timeframes
- Multiple rejections then approval

---

### Tab: Features
**Purpose:** Documentation of all security features

Explains all 9 features:
1. Login Activity Monitoring
2. User Activity Logs
3. Loan Approval Logs
4. Failed Login Attempt Monitoring
5. Online User Monitoring
6. Device and Browser Tracking
7. Suspicious Activity Detection
8. Administrative Notification Center
9. Audit Trail Management

---

## 🚨 Common Issues and Solutions

### Issue: "Excessive Failed Logins"
**Cause:** User forgot password  
**Solution:** Have user reset password, account auto-unlocks in 15 min  
**Prevention:** Send password reset emails

### Issue: "Unusual IP Address"
**Cause:** User on VPN or traveling  
**Solution:** Verify with user, may be expected  
**Prevention:** Document approved VPN/office IP addresses

### Issue: "New Device Access"
**Cause:** User on new phone/laptop  
**Solution:** Confirm with user, update known devices list  
**Prevention:** Common occurrence, expected

### Issue: "After Hours Access"
**Cause:** Emergency work or remote access  
**Solution:** Review if legitimate, document in system  
**Prevention:** Set approved after-hours users

### Issue: "Missing Audit Records"
**Cause:** Database tables not created  
**Solution:** Run migration SQL file  
**Command:** `mysql -u root vikoba_db < database/2026_06_18_security_features.sql`

---

## 📋 Weekly Checklist

- [ ] Review Critical Alerts tab
- [ ] Check Failed Logins for brute force attempts
- [ ] Verify Online Users are legitimate
- [ ] Review Suspicious Activities
- [ ] Check Activity Logs for unusual patterns
- [ ] Verify after-hours access is authorized
- [ ] Review new user registrations
- [ ] Export activity logs for records
- [ ] Document any security incidents
- [ ] Check system performance/logs

---

## 🔐 Security Best Practices

1. **Regular Reviews** - Check dashboard weekly minimum
2. **Act on Alerts** - Don't ignore critical alerts
3. **Verify Activities** - Confirm unusual activities with users
4. **Document Changes** - Keep records of investigations
5. **Backup Logs** - Regular backup of audit data
6. **Access Control** - Only admins see full logs
7. **Report Incidents** - Document and report security events
8. **Educate Users** - Train staff on security practices
9. **Monitor Trends** - Look for patterns over time
10. **Update Policies** - Adjust based on logs

---

## 📞 When to Contact Support

Contact your system administrator if:
- No data shows in audit tables
- Dashboard shows errors
- Alerts are not being recorded
- Can't filter or search
- Performance is slow
- Need to archive old logs
- Suspicious activities escalate
- Users report account issues

---

## 💾 Data Retention

- **Login Activity:** 12 months
- **Failed Logins:** 6 months
- **Activity Logs:** 12 months  
- **Audit Trail:** 24 months (legal requirement)
- **Suspicious Activities:** Until acknowledged

Archive older data monthly to maintain performance.

---

## 📊 Sample Scenarios

### Scenario 1: Login Spike
**Dashboard shows:** 50 logins in one hour (unusual)  
**Investigation:**
1. Check Online Users - who's logged in?
2. Check Activity Logs - what are they doing?
3. Verify - is it a system event or attack?
4. Decide - block if suspicious, document if normal

### Scenario 2: Failed Logins
**Dashboard shows:** 5+ failed logins for "john.doe"  
**System action:** Account locked automatically  
**Your action:**
1. Verify in Failed Logins tab
2. Contact John - ask if he knows what happened
3. Have him reset password
4. Document in incident log
5. Monitor for further attempts

### Scenario 3: Unusual Access
**Dashboard shows:** "Unusual Location" alert  
**Investigation:**
1. Check login IP address
2. Verify against known locations
3. Check device/browser info
4. Contact user - is it them?
5. If yes, add to known devices
6. If no, disable account

---

## ✅ Quick Decision Tree

```
See a Security Alert?
├─ CRITICAL?
│  ├─ YES → Act immediately, disable if needed, document
│  └─ NO → Continue below
├─ FAILED LOGINS > 5?
│  ├─ YES → Contact user, reset password
│  └─ NO → Continue below
├─ UNUSUAL DEVICE/LOCATION?
│  ├─ YES → Verify with user
│  └─ NO → Continue below
├─ UNUSUAL TIME (3 AM)?
│  ├─ YES → Verify it's authorized
│  └─ NO → Monitor and document
└─ Mark as reviewed when done
```

---

**Last Updated:** 2026-06-18  
**Version:** 1.0.0  
**For Admins Only**
