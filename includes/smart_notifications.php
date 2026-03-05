<?php
/**
 * نظام إشعارات ذكي - يمنع الإشعارات الذاتية
 */

require_once 'database.php';

class SmartNotifications {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * إرسال إشعار ذكي (لا يرسل للمستخدم عن أفعاله الخاصة)
     */
    public function send($options = []) {
        $defaults = [
            'sender_id' => null,
            'receiver_id' => null,
            'title' => '',
            'message' => '',
            'link' => '',
            'allow_self' => false  // هل تسمح بالإشعارات الذاتية؟ (نادراً ما يكون هذا مطلوباً)
        ];
        
        $options = array_merge($defaults, $options);
        
        // التحقق من البيانات الأساسية
        if (!$options['receiver_id']) {
            error_log("SmartNotification: Missing receiver_id");
            return false;
        }
        
        // 🔥 القاعدة الذهبية: لا ترسل إشعاراً للمستخدم عن فعله هو
        if (!$options['allow_self'] && $options['sender_id'] == $options['receiver_id']) {
            // فقط سجل للتاريخ
            $this->logSelfAction($options['sender_id'], $options['title']);
            return false;
        }
        
        // إرسال الإشعار الحقيقي
        return $this->insertNotification(
            $options['receiver_id'],
            $options['title'],
            $options['message'],
            $options['link']
        );
    }
    
    /**
     * إرسال إشعار عند إنشاء مستند (خاص بالمسؤولين والرؤساء فقط)
     */
    public function sendDocumentCreated($document_id, $creator_id, $document_title) {
        // جلب رئيس قسم الموظف
        $stmt = $this->db->prepare("
            SELECT supervisor_id 
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$creator_id]);
        $supervisor_id = $stmt->fetchColumn();
        
        // فقط أرسل إشعاراً إذا كان هناك رئيس قسم
        if ($supervisor_id && $supervisor_id != $creator_id) {
            return $this->send([
                'sender_id' => $creator_id,
                'receiver_id' => $supervisor_id,
                'title' => 'مستند جديد',
                'message' => 'قام الموظف ' . $this->getUserName($creator_id) . ' بإنشاء مستند جديد: ' . $document_title,
                'link' => '../documents/view_document.php?id=' . $document_id
            ]);
        }
        
        return false;
    }
    
    /**
     * إرسال إشعار عند الموافقة/الرفض (هذا منطقي)
     */
    public function sendDocumentDecision($document_id, $decision_by, $employee_id, $decision, $reason = '') {
        // فقط أرسل إذا كان القرار من شخص آخر
        if ($decision_by == $employee_id) {
            return false;
        }
        
        $title = ($decision === 'approve') ? 'تمت الموافقة على مستندك' : 'تم رفض مستندك';
        $message = ($decision === 'approve')
            ? 'تمت الموافقة على المستند الخاص بك من قبل ' . $this->getUserName($decision_by)
            : 'تم رفض المستند الخاص بك من قبل ' . $this->getUserName($decision_by) . ($reason ? ': ' . $reason : '');
        
        return $this->send([
            'sender_id' => $decision_by,
            'receiver_id' => $employee_id,
            'title' => $title,
            'message' => $message,
            'link' => '../documents/view_document.php?id=' . $document_id
        ]);
    }
    
    /**
     * إشعارات المنطقية فقط - قائمة بالأحداث المسموح بها
     */
    public function getLogicalEvents() {
        return [
            'document_approved' => 'موافقة على مستند',
            'document_rejected' => 'رفض مستند',
            'document_forwarded' => 'توجيه مستند',
            'document_commented' => 'تعليق على مستند',
            'task_assigned' => 'تعيين مهمة',
            'deadline_reminder' => 'تذكير بموعد نهائي',
            'document_requires_action' => 'يتطلب إجراء',
        ];
    }
    
    /**
     * الأحداث التي لا ترسل إشعارات (ذاتية)
     */
    public function getSelfEventsToBlock() {
        return [
            'document_created_by_self',
            'document_updated_by_self', 
            'attachment_added_by_self',
            'draft_saved_by_self',
            'profile_updated_by_self'
        ];
    }
    
    private function insertNotification($user_id, $title, $message, $link) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notifications (user_id, title, message, link, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            return $stmt->execute([$user_id, $title, $message, $link]);
        } catch (Exception $e) {
            error_log("Notification insert error: " . $e->getMessage());
            return false;
        }
    }
    
    private function getUserName($user_id) {
        $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn() ?: 'مستخدم';
    }
    
    private function logSelfAction($user_id, $action) {
        // يمكنك تسجيل هذا في جدول منفصل للتحليل
        $stmt = $this->db->prepare("
            INSERT INTO notification_logs (user_id, action_type, is_self_action, created_at)
            VALUES (?, ?, 1, NOW())
        ");
        $stmt->execute([$user_id, $action]);
    }
}

// إنشاء نسخة عامة للاستخدام السريع
function sendSmartNotification($sender_id, $receiver_id, $title, $message, $link = '', $allow_self = false) {
    static $notifier = null;
    
    if ($notifier === null) {
        $notifier = new SmartNotifications();
    }
    
    return $notifier->send([
        'sender_id' => $sender_id,
        'receiver_id' => $receiver_id,
        'title' => $title,
        'message' => $message,
        'link' => $link,
        'allow_self' => $allow_self
    ]);
}
?>