<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Notification Model
 * 
 * Manages notifications for booking remarks
 */
class Notification_Model extends CI_Model
{
	/**
	 * Create a new notification
	 * 
	 * @param array $data Notification data
	 * @return int Insert ID
	 */
	function Create($data)
	{
		$notification_data = array(
			'user_id' => $data['user_id'],
			'type' => isset($data['type']) ? $data['type'] : 'remark',
			'owner_type' => $data['owner_type'],
			'owner_id' => $data['owner_id'],
			'remark_id' => isset($data['remark_id']) ? $data['remark_id'] : null,
			'message' => $data['message'],
			'is_read' => 0,
			'created_at' => date('Y-m-d H:i:s')
		);
		
		$this->db->insert('notification', $notification_data);
		return $this->db->insert_id();
	}

	/**
	 * An active OP or OP lead can see a booking through any of its assignees.
	 * Match the team's primary and additional memberships used by Booking.
	 */
	private function _pending_bc_recipient_condition()
	{
		$assignees = '(booking.SalesAgent, booking.SalesAgent2, booking.BookingOP)';
		return "recipient.Status = 'Y' AND recipient.Level IN ('40', '45') AND (
			recipient.AdminID IN $assignees
			OR EXISTS (
				SELECT 1 FROM admin assignee
				WHERE assignee.AdminID IN $assignees
					AND assignee.Status = 'Y' AND assignee.TeamID > 0
					AND (
						assignee.TeamID = recipient.TeamID
						OR (recipient.Level = '45' AND EXISTS (
							SELECT 1 FROM admin_team
							WHERE admin_team.AdminID = recipient.AdminID
								AND admin_team.TeamID = assignee.TeamID
						))
					)
			)
		)";
	}

	/**
	 * Send one reminder per recipient and entry into a stage, even when cron overlaps.
	 */
	function Create_Booking_Stage_Reminders(array $reminder_hours)
	{
		$rules = array(
			'SAD' => array('draft_bc_reminder', 'Draft BC', "recipient.AdminID = booking.SalesAgent2 AND recipient.Status = 'Y'"),
			'PB'  => array('pending_bc_reminder', 'Pending BC', $this->_pending_bc_recipient_condition()),
		);
		$now = time();
		$created = 0;

		foreach ($rules as $status => list($type, $label, $recipient_condition)) {
			$hours = $reminder_hours[$type . '_hours'];
			if ($hours <= 0) {
				continue;
			}

			$cutoff = date('Y-m-d H:i:s', $now - $hours * 3600);
			$unit = $hours === 1 ? 'hour' : 'hours';
			$message = "This booking has been in $label for more than $hours $unit.";

			// Ignore same-status log entries so saving the booking does not restart the timer.
			// For bookings with no transition history, use the creation date and log ID 0.
			$result = $this->db->query("
				INSERT INTO notification (user_id, type, owner_type, owner_id, booking_status_log_id, message, is_read, created_at)
				SELECT recipient.AdminID, ?, 'booking', booking.BookingID, COALESCE(stage.id, 0), ?, 0, ?
				FROM booking
				LEFT JOIN booking_status_log stage ON stage.id = (
					SELECT entry.id FROM booking_status_log entry
					WHERE entry.booking_id = booking.BookingID
						AND (entry.from_status IS NULL OR entry.from_status <> entry.to_status)
					ORDER BY entry.id DESC
					LIMIT 1
				)
				JOIN admin recipient ON $recipient_condition
				WHERE booking.Status = ? AND booking.CancelStatus = 'N'
					AND (stage.id IS NULL OR stage.to_status = ?)
					AND COALESCE(stage.created_at, booking.InsertDate) < ?
					AND NOT EXISTS (
						SELECT 1 FROM notification sent
						WHERE sent.user_id = recipient.AdminID AND sent.type = ?
							AND sent.owner_type = 'booking' AND sent.owner_id = booking.BookingID
							AND sent.booking_status_log_id = COALESCE(stage.id, 0)
					)
				ON DUPLICATE KEY UPDATE NotificationID = notification.NotificationID
			", array($type, $message, date('Y-m-d H:i:s', $now), $status, $status, $cutoff, $type));

			if (!$result) {
				return false;
			}
			$created += $this->db->affected_rows();
		}

		return $created;
	}

	/**
	 * Apply role-based visibility WHERE clauses to the current $this->db builder.
	 * Caller must have already joined `booking` so booking.SalesAgent / BookingOP
	 * are reachable. Mutates the builder; returns nothing.
	 *
	 * @param int $user_id
	 */
	private function _apply_visibility_filter($user_id)
	{
		// Fetch the level via a raw query so we don't pollute the AR state
		// that the caller has already configured on the notification builder.
		$row = $this->db->query(
			'SELECT level FROM admin WHERE AdminID = ? LIMIT 1',
			array((int)$user_id)
		)->row();
		$user_level = !empty($row) ? $row->level : null;

		$uid = (int)$user_id;

		// Bell/page shows remark notifications only to the booking's SalesAgent / BookingOP.
		// @mention rows (recipient is neither) stay in the Messages dropdown.
		$this->db->where("(notification.type != 'remark' OR booking.SalesAgent = $uid OR booking.BookingOP = $uid)", NULL, FALSE);

		// Reminders follow the current TC2 assignment and OP booking scope.
		$op_scope = $this->_pending_bc_recipient_condition();
		$this->db->where("(notification.type != 'draft_bc_reminder' OR booking.SalesAgent2 = $uid)", NULL, FALSE);
		$this->db->where("(notification.type != 'pending_bc_reminder' OR EXISTS (
			SELECT 1 FROM admin recipient WHERE recipient.AdminID = $uid AND $op_scope
		))", NULL, FALSE);

		// Front-line roles (SALES AGENT / OP) are scoped to bookings they sit on.
		// Match EITHER seat (SalesAgent OR BookingOP), not the seat implied by
		// their current level — otherwise a staff member moved between roles
		// (e.g. TC -> OP) loses the notifications on bookings they were assigned
		// to under their old role. Higher roles keep their unrestricted view.
		if ($user_level == 20 || $user_level == 40) {
			$this->db->where("(notification.owner_type != 'booking'
				OR notification.type IN ('draft_bc_reminder', 'pending_bc_reminder')
				OR booking.SalesAgent = $uid OR booking.BookingOP = $uid)", NULL, FALSE);
		}
	}

	/**
	 * Get unread notifications count for a user
	 *
	 * @param int $user_id Admin ID
	 * @return int Count of unread notifications
	 */
	function Get_Unread_Count($user_id)
	{
		$this->db->where('notification.user_id', $user_id);
		$this->db->where('notification.is_read', 0);
		$this->db->join('booking', 'booking.BookingID = notification.owner_id AND notification.owner_type = "booking"', 'left');
		$this->_apply_visibility_filter($user_id);
		return $this->db->count_all_results('notification');
	}

	/**
	 * Get notifications for a user, optionally filtered by category and read state.
	 *
	 * @param int    $user_id  Admin ID
	 * @param int    $limit    Pagination limit
	 * @param int    $offset   Pagination offset
	 * @param string $category Optional category key (see notification_category_helper)
	 * @param mixed  $is_read  null = all; 0 = unread only; 1 = read only
	 * @return array Notification objects
	 */
	function Get_Notifications($user_id, $limit = 20, $offset = 0, $category = null, $is_read = null)
	{
		$this->db->select('notification.*, booking.BookingNumber, booking.BookingID, booking.Customer, admin.Name AS CommenterName');
		$this->db->join('booking', 'booking.BookingID = notification.owner_id AND notification.owner_type = "booking"', 'left');
		$this->db->join('remark', 'remark.RemarkID = notification.remark_id', 'left');
		$this->db->join('admin', 'admin.AdminID = remark.commenter_id', 'left');
		$this->db->where('notification.user_id', $user_id);
		$this->_apply_visibility_filter($user_id);

		if (!empty($category) && $category !== 'all') {
			$this->load->helper('notification_category');
			$types = notification_category_to_types($category);
			if (!empty($types)) {
				$this->db->where_in('notification.type', $types);
			}
		}

		if ($is_read === 0 || $is_read === '0' || $is_read === 1 || $is_read === '1') {
			$this->db->where('notification.is_read', (int)$is_read);
		}

		$this->db->order_by('notification.created_at', 'DESC');
		$this->db->limit($limit, $offset);
		return $this->db->get('notification')->result();
	}

	/**
	 * Get unread notification counts grouped by user-facing category.
	 * Returns associative array keyed by category, plus a 'total' key.
	 *
	 * @param int $user_id Admin ID
	 * @return array
	 */
	function Get_Category_Counts($user_id)
	{
		$this->load->helper('notification_category');

		$this->db->select('notification.type, COUNT(*) AS cnt', FALSE);
		$this->db->join('booking', 'booking.BookingID = notification.owner_id AND notification.owner_type = "booking"', 'left');
		$this->db->where('notification.user_id', $user_id);
		$this->db->where('notification.is_read', 0);
		$this->_apply_visibility_filter($user_id);
		$this->db->group_by('notification.type');
		$rows = $this->db->get('notification')->result();

		$by_type = array();
		foreach ($rows as $r) {
			$by_type[$r->type] = (int)$r->cnt;
		}
		return fold_category_counts($by_type);
	}

	/**
	 * Mark all notifications in a single category as read for a user.
	 *
	 * @param int    $user_id  Admin ID
	 * @param string $category Category key (see notification_category_helper)
	 * @return bool
	 */
	function Mark_Category_As_Read($user_id, $category)
	{
		$this->load->helper('notification_category');
		$types = notification_category_to_types($category);
		if (empty($types)) {
			return false;
		}
		$this->db->where('user_id', $user_id);
		$this->db->where('is_read', 0);
		$this->db->where_in('type', $types);
		$this->db->set('is_read', 1);
		$this->db->set('read_at', date('Y-m-d H:i:s'));
		$this->db->update('notification');
		return $this->db->affected_rows() >= 0;
	}

	/**
	 * Mark notification as read
	 * 
	 * @param int $notification_id Notification ID
	 * @param int $user_id User ID (for security check)
	 * @return bool Success status
	 */
	function Mark_As_Read($notification_id, $user_id)
	{
		$this->db->where('NotificationID', $notification_id);
		$this->db->where('user_id', $user_id);
		$this->db->set('is_read', 1);
		$this->db->set('read_at', date('Y-m-d H:i:s'));
		$this->db->update('notification');
		return $this->db->affected_rows() > 0;
	}

	/**
	 * Mark notification as unread
	 *
	 * @param int $notification_id Notification ID
	 * @param int $user_id User ID (for security check)
	 * @return bool Success status
	 */
	function Mark_As_Unread($notification_id, $user_id)
	{
		$this->db->where('NotificationID', $notification_id);
		$this->db->where('user_id', $user_id);
		$this->db->set('is_read', 0);
		$this->db->set('read_at', NULL);
		$this->db->update('notification');
		return $this->db->affected_rows() > 0;
	}

	/**
	 * Mark all notifications as read for a user
	 * 
	 * @param int $user_id Admin ID
	 * @return bool Success status
	 */
	function Mark_All_As_Read($user_id)
	{
		$this->db->where('user_id', $user_id);
		$this->db->where('is_read', 0);
		$this->db->set('is_read', 1);
		$this->db->set('read_at', date('Y-m-d H:i:s'));
		$this->db->update('notification');
		return $this->db->affected_rows() >= 0; // Return true even if no rows updated
	}

	/**
	 * Enrich an array of admin rows with a unique single-token "handle"
	 * derived from admin.Name (used for @mention display rendering).
	 *
	 * Rule: lowercase [a-z0-9] only. Empty -> "user{AdminID}". On collision,
	 * append AdminID to every colliding admin so each one stays unique.
	 *
	 * @param array $admins Rows with at least {AdminID, Name}
	 * @return array Same rows with an added "handle" property
	 */
	function Build_Admin_Handles($admins)
	{
		if (empty($admins)) {
			return $admins;
		}

		$base_counts = array();
		foreach ($admins as $a) {
			$base = strtolower(preg_replace('/[^a-z0-9]/i', '', $a->Name));
			if ($base === '') {
				$base = 'user' . intval($a->AdminID);
			}
			$a->_handle_base = $base;
			$base_counts[$base] = isset($base_counts[$base]) ? $base_counts[$base] + 1 : 1;
		}

		foreach ($admins as $a) {
			if ($base_counts[$a->_handle_base] > 1) {
				$a->handle = $a->_handle_base . intval($a->AdminID);
			} else {
				$a->handle = $a->_handle_base;
			}
			unset($a->_handle_base);
		}

		return $admins;
	}

	/**
	 * Resolve parsed @handles back to AdminIDs of active admins.
	 * Lowercased + deduped. Unknown or inactive handles silently dropped.
	 *
	 * @param array $handles Handle strings parsed from remark content
	 * @return array Array of AdminIDs (order follows admin row order)
	 */
	function Resolve_Handles_To_User_Ids($handles)
	{
		if (empty($handles)) {
			return array();
		}

		$wanted = array_unique(array_map('strtolower', $handles));

		$this->db->select('AdminID, Name');
		$this->db->where('Status', 'Y');
		$admins = $this->db->get('admin')->result();

		$admins = $this->Build_Admin_Handles($admins);

		$ids = array();
		foreach ($admins as $a) {
			if (in_array($a->handle, $wanted, true)) {
				$ids[] = intval($a->AdminID);
			}
		}
		return array_values(array_unique($ids));
	}

	/**
	 * Create one 'remark' notification per active tagged admin. The commenter
	 * is never notified (even if listed in $user_ids). Idempotent on
	 * (user_id, remark_id) — re-running inserts no duplicate row.
	 *
	 * @param int    $booking_id
	 * @param int    $remark_id
	 * @param int    $commenter_id    Admin who created the remark
	 * @param string $remark_content  (unused; reserved for future message variants)
	 * @param array  $user_ids        Tagged AdminIDs to notify
	 * @return int   Number of notification rows actually inserted
	 */
	function Create_Remark_Notifications_For_Users($booking_id, $remark_id, $commenter_id, $remark_content, $user_ids)
	{
		$this->db->select('Name');
		$this->db->where('AdminID', $commenter_id);
		$commenter = $this->db->get('admin')->row();
		$commenter_name = !empty($commenter) ? $commenter->Name : 'Unknown';

		$message = $commenter_name . ' added a remark';

		// Never self-notify
		$user_ids = array_diff($user_ids, array($commenter_id));

		$notifications_created = 0;

		foreach ($user_ids as $user_id) {
			$this->db->select('AdminID');
			$this->db->where('AdminID', $user_id);
			$this->db->where('Status', 'Y');
			$admin = $this->db->get('admin')->row();

			if (empty($admin)) {
				continue;
			}

			$this->db->where('user_id', $user_id);
			$this->db->where('remark_id', $remark_id);
			$existing = $this->db->get('notification')->row();

			if (empty($existing)) {
				$this->Create(array(
					'user_id'    => $user_id,
					'type'       => 'remark',
					'owner_type' => 'booking',
					'owner_id'   => $booking_id,
					'remark_id'  => $remark_id,
					'message'    => $message,
				));
				$notifications_created++;
			}
		}

		return $notifications_created;
	}

	/**
	 * Auto-notify the booking's SalesAgent and BookingOP for every internal
	 * remark. Skips inactive admins, skips the commenter, collapses SA==OP to
	 * a single row, and is idempotent on (user_id, remark_id).
	 *
	 * @param int    $booking_id
	 * @param int    $remark_id
	 * @param int    $commenter_id
	 * @param string $remark_content  (unused; reserved for future message variants)
	 * @return int   Number of notification rows inserted
	 */
	function Create_Remark_Notifications($booking_id, $remark_id, $commenter_id, $remark_content)
	{
		$this->db->select('BookingID, SalesAgent, BookingOP');
		$this->db->where('BookingID', $booking_id);
		$booking = $this->db->get('booking')->row();

		if (empty($booking)) {
			return 0;
		}

		$this->db->select('Name');
		$this->db->where('AdminID', $commenter_id);
		$commenter = $this->db->get('admin')->row();
		$commenter_name = !empty($commenter) ? $commenter->Name : 'Unknown';

		$message = $commenter_name . ' added a remark';

		$notifications_created = 0;
		$notified_user_ids = array();

		$targets = array();
		if (!empty($booking->SalesAgent)) { $targets[] = $booking->SalesAgent; }
		if (!empty($booking->BookingOP))  { $targets[] = $booking->BookingOP; }

		foreach ($targets as $uid) {
			if ($uid == $commenter_id || in_array($uid, $notified_user_ids)) {
				continue;
			}

			$this->db->select('AdminID');
			$this->db->where('AdminID', $uid);
			$this->db->where('Status', 'Y');
			$admin = $this->db->get('admin')->row();

			if (empty($admin)) {
				$notified_user_ids[] = $uid;
				continue;
			}

			$this->db->where('user_id', $uid);
			$this->db->where('remark_id', $remark_id);
			$existing = $this->db->get('notification')->row();

			if (empty($existing)) {
				$this->Create(array(
					'user_id'    => $uid,
					'type'       => 'remark',
					'owner_type' => 'booking',
					'owner_id'   => $booking_id,
					'remark_id'  => $remark_id,
					'message'    => $message,
				));
				$notifications_created++;
			}
			$notified_user_ids[] = $uid;
		}

		return $notifications_created;
	}

	/**
	 * Create notification for Sales Agent and BookingOP when customer adds a remark
	 *
	 * @param int $booking_id Booking ID
	 * @param int $remark_id Remark ID
	 * @param int $sales_agent_id Sales Agent Admin ID
	 * @param string $customer_name Customer name
	 * @param string $remark_content Remark content
	 * @param int|null $booking_op_id BookingOP Admin ID
	 * @return bool Success status
	 */
	function Create_Customer_Remark_Notification($booking_id, $remark_id, $sales_agent_id, $customer_name, $remark_content, $booking_op_id = null)
	{
		$message = 'Customer added a remark';
		$notified = false;
		$notified_user_ids = array();

		// Notify Sales Agent
		if (!empty($sales_agent_id) && !in_array($sales_agent_id, $notified_user_ids)) {
			$this->db->select('AdminID');
			$this->db->where('AdminID', $sales_agent_id);
			$this->db->where('Status', 'Y');
			$sales_agent = $this->db->get('admin')->row();

			if (!empty($sales_agent)) {
				$this->db->where('user_id', $sales_agent_id);
				$this->db->where('remark_id', $remark_id);
				$existing = $this->db->get('notification')->row();

				if (empty($existing)) {
					$this->Create(array(
						'user_id' => $sales_agent_id,
						'type' => 'remark',
						'owner_type' => 'booking',
						'owner_id' => $booking_id,
						'remark_id' => $remark_id,
						'message' => $message
					));
					$notified = true;
				}
			}
			$notified_user_ids[] = $sales_agent_id;
		}

		// Notify BookingOP
		if (!empty($booking_op_id) && !in_array($booking_op_id, $notified_user_ids)) {
			$this->db->select('AdminID');
			$this->db->where('AdminID', $booking_op_id);
			$this->db->where('Status', 'Y');
			$booking_op = $this->db->get('admin')->row();

			if (!empty($booking_op)) {
				$this->db->where('user_id', $booking_op_id);
				$this->db->where('remark_id', $remark_id);
				$existing = $this->db->get('notification')->row();

				if (empty($existing)) {
					$this->Create(array(
						'user_id' => $booking_op_id,
						'type' => 'remark',
						'owner_type' => 'booking',
						'owner_id' => $booking_id,
						'remark_id' => $remark_id,
						'message' => $message
					));
					$notified = true;
				}
			}
		}

		return $notified;
	}

	/**
	 * Create notifications for relevant users when a booking is updated
	 *
	 * @param int $booking_id Booking ID
	 * @param int $updater_id Admin ID who updated the booking
	 * @param string $updater_name Name of the user who updated the booking
	 * @param string $change_summary Optional short summary of what changed
	 * @return int Number of notifications created
	 */
	function Create_Booking_Updated_Notification($booking_id, $updater_id, $updater_name, $change_summary = '')
	{
		// Get booking details
		$this->db->select('BookingID, BookingNumber, SalesAgent, BookingOP');
		$this->db->where('BookingID', $booking_id);
		$booking = $this->db->get('booking')->row();

		if (empty($booking)) {
			return 0;
		}

		$message = $updater_name . ' updated a booking';
		if (!empty($change_summary)) {
			$message .= ': ' . $change_summary;
		}
		$notifications_created = 0;
		$notified_user_ids = array();

		// Notify SalesAgent (TC)
		if (!empty($booking->SalesAgent) && $booking->SalesAgent != $updater_id && !in_array($booking->SalesAgent, $notified_user_ids)) {
			$this->db->select('AdminID');
			$this->db->where('AdminID', $booking->SalesAgent);
			$this->db->where('Status', 'Y');
			$sa = $this->db->get('admin')->row();

			if (!empty($sa)) {
				$notification_data = array(
					'user_id' => $booking->SalesAgent,
					'type' => 'booking_updated',
					'owner_type' => 'booking',
					'owner_id' => $booking_id,
					'remark_id' => null,
					'message' => $message
				);
				$this->Create($notification_data);
				$notifications_created++;
				$notified_user_ids[] = $booking->SalesAgent;
			}
		}

		// Notify BookingOP
		if (!empty($booking->BookingOP) && $booking->BookingOP != $updater_id && !in_array($booking->BookingOP, $notified_user_ids)) {
			$this->db->select('AdminID');
			$this->db->where('AdminID', $booking->BookingOP);
			$this->db->where('Status', 'Y');
			$op = $this->db->get('admin')->row();

			if (!empty($op)) {
				$notification_data = array(
					'user_id' => $booking->BookingOP,
					'type' => 'booking_updated',
					'owner_type' => 'booking',
					'owner_id' => $booking_id,
					'remark_id' => null,
					'message' => $message
				);
				$this->Create($notification_data);
				$notifications_created++;
				$notified_user_ids[] = $booking->BookingOP;
			}
		}

		return $notifications_created;
	}

	/**
	 * Create notifications for SalesAgent (TC) and BookingOP when a customer submits a review
	 * Message format: "{Customer} submitted a review"
	 *
	 * @param int $booking_id Booking ID
	 * @param string $customer_name Customer name from booking
	 * @return int Number of notifications created
	 */
	function Create_Review_Submitted_Notification($booking_id, $customer_name)
	{
		$this->db->select('BookingID, BookingNumber, SalesAgent, BookingOP');
		$this->db->where('BookingID', $booking_id);
		$booking = $this->db->get('booking')->row();

		if (empty($booking)) {
			return 0;
		}

		$actor = !empty($customer_name) ? $customer_name : 'Customer';
		$message = $actor . ' submitted a review';
		$notifications_created = 0;
		$notified_user_ids = array();

		// Notify SalesAgent (TC)
		if (!empty($booking->SalesAgent) && !in_array($booking->SalesAgent, $notified_user_ids)) {
			$this->db->select('AdminID');
			$this->db->where('AdminID', $booking->SalesAgent);
			$this->db->where('Status', 'Y');
			$sa = $this->db->get('admin')->row();

			if (!empty($sa)) {
				$this->Create(array(
					'user_id' => $booking->SalesAgent,
					'type' => 'review_submitted',
					'owner_type' => 'booking',
					'owner_id' => $booking_id,
					'remark_id' => null,
					'message' => $message
				));
				$notifications_created++;
				$notified_user_ids[] = $booking->SalesAgent;
			}
		}

		// Notify BookingOP
		if (!empty($booking->BookingOP) && !in_array($booking->BookingOP, $notified_user_ids)) {
			$this->db->select('AdminID');
			$this->db->where('AdminID', $booking->BookingOP);
			$this->db->where('Status', 'Y');
			$op = $this->db->get('admin')->row();

			if (!empty($op)) {
				$this->Create(array(
					'user_id' => $booking->BookingOP,
					'type' => 'review_submitted',
					'owner_type' => 'booking',
					'owner_id' => $booking_id,
					'remark_id' => null,
					'message' => $message
				));
				$notifications_created++;
				$notified_user_ids[] = $booking->BookingOP;
			}
		}

		return $notifications_created;
	}
}

