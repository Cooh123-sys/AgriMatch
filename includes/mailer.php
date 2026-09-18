<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ---------- SHARED SMTP CREDENTIALS ----------
define('SMTP_EMAIL',    'makinafrezer@gmail.com');   // <-- your Gmail address
define('SMTP_APP_PASS', 'ejdhjxsrmmpvdnkh'); // <-- regenerate this, don't reuse the leaked one

// ---------- ADMIN NOTIFICATION RECIPIENT ----------
// This can be the same Gmail account, or a separate admin inbox
define('ADMIN_EMAIL', 'makinafrezer@gmail.com');
define('ADMIN_NAME',  'AgriMatch Admin');


/**
 * Low-level helper: configures and returns a ready-to-send PHPMailer instance.
 */
function buildMailer($toEmail, $toName, $subject, $htmlBody) {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_EMAIL;
    $mail->Password   = SMTP_APP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom(SMTP_EMAIL, 'AgriMatch');
    $mail->addAddress($toEmail, $toName);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $htmlBody;

    return $mail;
}


/**
 * Sends the account status email to the farmer/buyer themselves,
 * AND a matching notification email to the admin.
 *
 * $status can be: 'pending', 'verified', or 'rejected'
 */
function sendStatusEmail($toEmail, $toName, $role, $status) {

    // ---------- BUILD USER-FACING EMAIL CONTENT ----------
    $subject = "AgriMatch Account " . ucfirst($status);

    if ($status === 'verified') {
        $userBody = "
            <h3>Hello {$toName},</h3>
            <p>Congratulations! Your <strong>{$role}</strong> account on <strong>AgriMatch</strong> has been verified.</p>
            <p>You can now log in and start using the platform.</p>
            <p><a href='http://localhost/AgriMatch/auth/login.php'>Click here to log in</a></p>
            <br>
            <p>Regards,<br>AgriMatch Admin Team</p>
        ";
    } elseif ($status === 'rejected') {
        $userBody = "
            <h3>Hello {$toName},</h3>
            <p>We regret to inform you that your <strong>{$role}</strong> account registration on
            <strong>AgriMatch</strong> has been rejected after review of your submitted documents.</p>
            <p>If you believe this is a mistake, please contact the administrator or register again
            with correct documentation.</p>
            <br>
            <p>Regards,<br>AgriMatch Admin Team</p>
        ";
    } else { // pending
        $userBody = "
            <h3>Hello {$toName},</h3>
            <p>Thank you for registering as a <strong>{$role}</strong> on <strong>AgriMatch</strong>.</p>
            <p>Your account and submitted documents are now <strong>pending review</strong> by our admin team.
            You will receive another email once your account has been verified or if further action is needed.</p>
            <br>
            <p>Regards,<br>AgriMatch Admin Team</p>
        ";
    }

    // ---------- SEND TO USER ----------
    $userSent = false;
    try {
        $mail = buildMailer($toEmail, $toName, $subject, $userBody);
        $mail->send();
        $userSent = true;
    } catch (Exception $e) {
        error_log("User email failed to send to {$toEmail}: " . $e->getMessage());
    }

    // ---------- BUILD + SEND ADMIN NOTIFICATION ----------
    $adminSubject = "AgriMatch: {$role} account " . strtolower($status) . " — {$toName}";

    if ($status === 'pending') {
        $adminBody = "
            <h3>New {$role} registration</h3>
            <p><strong>{$toName}</strong> ({$toEmail}) has just registered as a <strong>{$role}</strong>
            and their account is now <strong>pending your review</strong>.</p>
            <p>Please log in to the admin dashboard to view their submitted documents and approve or reject the account.</p>
            <p><a href='http://localhost/AgriMatch/dashboard.php'>Go to Admin Dashboard</a></p>
        ";
    } elseif ($status === 'verified') {
        $adminBody = "
            <h3>Account Verified</h3>
            <p>You approved the <strong>{$role}</strong> account belonging to
            <strong>{$toName}</strong> ({$toEmail}). A confirmation email has been sent to them.</p>
        ";
    } else { // rejected
        $adminBody = "
            <h3>Account Rejected</h3>
            <p>You rejected the <strong>{$role}</strong> account belonging to
            <strong>{$toName}</strong> ({$toEmail}). A notification email has been sent to them.</p>
        ";
    }

    $adminSent = false;
    try {
        $adminMail = buildMailer(ADMIN_EMAIL, ADMIN_NAME, $adminSubject, $adminBody);
        $adminMail->send();
        $adminSent = true;
    } catch (Exception $e) {
        error_log("Admin notification email failed to send: " . $e->getMessage());
    }

    // Return true only if at least the user email succeeded (admin copy is a bonus, not critical)
    return $userSent;
}

/**
 * Sends a match-related notification email.
 *
 * $event can be: 'requested', 'accepted', 'rejected'
 * $recipientRole is the role of the PERSON RECEIVING this email ('farmer' or 'buyer')
 */
function sendMatchEmail($toEmail, $toName, $recipientRole, $event, $cropType, $otherPartyName = null, $otherPartyPhone = null, $otherPartyEmail = null) {

    $subject = "AgriMatch: Match " . ucfirst($event) . " — {$cropType}";

    if ($event === 'requested') {
        // Only farmers receive this — a buyer wants to match with their listing
        $body = "
            <h3>Hello {$toName},</h3>
            <p>A buyer has requested to match with your <strong>{$cropType}</strong> listing on AgriMatch.</p>
            <p>Please log in to review the request and accept or reject it.</p>
            <p><a href='http://localhost/AgriMatch/farmer/matches.php'>View Match Request</a></p>
            <br>
            <p>Regards,<br>AgriMatch Team</p>
        ";

    } elseif ($event === 'accepted') {
        // Sent to BOTH parties once a farmer accepts
        $contactBlock = "";
        if ($otherPartyName) {
            $label = $recipientRole === 'farmer' ? 'Buyer' : 'Farmer';
            $contactBlock = "
                <p><strong>{$label} Contact Details:</strong><br>
                Name: {$otherPartyName}<br>
                Phone: {$otherPartyPhone}<br>
                Email: {$otherPartyEmail}</p>
            ";
        }
        $body = "
            <h3>Hello {$toName},</h3>
            <p>Good news! Your <strong>{$cropType}</strong> match on AgriMatch has been <strong>accepted</strong>.</p>
            {$contactBlock}
            <p>You can now contact each other directly to arrange the transaction.</p>
            <br>
            <p>Regards,<br>AgriMatch Team</p>
        ";

    } else { // rejected
        // Only the buyer receives this — the farmer declined
        $body = "
            <h3>Hello {$toName},</h3>
            <p>Unfortunately, your match request for <strong>{$cropType}</strong> was <strong>declined</strong> by the farmer.</p>
            <p>Don't worry — other matching farmers may still be available. Log in to check your matches.</p>
            <p><a href='http://localhost/AgriMatch/buyer/matched_farmers.php'>View Matched Farmers</a></p>
            <br>
            <p>Regards,<br>AgriMatch Team</p>
        ";
    }

    try {
        $mail = buildMailer($toEmail, $toName, $subject, $body);
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Match email failed to send to {$toEmail}: " . $e->getMessage());
        return false;
    }
}