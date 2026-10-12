<?php
class Guest_List_Room extends MY_Controller
{
	function __construct()
	{
		parent::__construct();
		$this->load->model('Guest_List_Room_Model');
		$this->load->model('Booking_Model');
		$this->load->model('Universal_Model');
	}

	function Read()
	{
		$rooms = $this->Guest_List_Room_Model->Read_Rooms();
		echo json_encode($rooms);
	}

	function Create()
	{
		if($this->input->post()) {
			$booking_id = $this->input->post('booking_id');
			if($this->_is_gl_locked($booking_id)) {
				echo json_encode(array('success' => false, 'message' => 'Room management is locked because Guest List has been locked'));
				return;
			}
			$room_id = $this->Guest_List_Room_Model->Create();
			$this->Booking_Model->Sync_GL_From_Rooms($booking_id);
			$this->Booking_Model->Flag_Autocount_Resync($booking_id);
			echo json_encode(array('success' => true, 'room_id' => $room_id, 'message' => 'Room successfully created'));
		} else {
			echo json_encode(array('success' => false, 'message' => 'Invalid request'));
		}
	}

	function Update()
	{
		if($this->input->post()) {
			$room = $this->_get_room_by_id($this->input->post('room_id'));
			if($room && $this->_is_gl_locked($room['booking_id'])) {
				echo json_encode(array('success' => false, 'message' => 'Room management is locked because Guest List has been locked'));
				return;
			}
			$this->Guest_List_Room_Model->Update();
			$this->Booking_Model->Sync_GL_From_Rooms($room['booking_id']);
			$this->Booking_Model->Flag_Autocount_Resync($room['booking_id']);
			echo json_encode(array('success' => true, 'message' => 'Room successfully updated'));
		} else {
			echo json_encode(array('success' => false, 'message' => 'Invalid request'));
		}
	}

	function Delete()
	{
		$room = $this->_get_room_by_id($this->input->get('room_id'));
		if($room && $this->_is_gl_locked($room['booking_id'])) {
			echo json_encode(array('success' => false, 'message' => 'Room management is locked because Guest List has been locked'));
			return;
		}
		$this->Guest_List_Room_Model->Delete();
		$this->Booking_Model->Sync_GL_From_Rooms($room['booking_id']);
		$this->Booking_Model->Flag_Autocount_Resync($room['booking_id']);
		echo json_encode(array('success' => true, 'message' => 'Room successfully deleted'));
	}

	function Add_Count()
	{
		if($this->input->post()) {
			$room_id = $this->input->post('room_id');
			$type = $this->input->post('type');
			$allowed = array('adult_count', 'child_count', 'infant_count');
			if(!in_array($type, $allowed)) {
				echo json_encode(array('success' => false, 'message' => 'Invalid type'));
				return;
			}
			$room = $this->_get_room_by_id($room_id);
			if(!$room) {
				echo json_encode(array('success' => false, 'message' => 'Room not found'));
				return;
			}
			if($this->_is_gl_locked($room['booking_id'])) {
				echo json_encode(array('success' => false, 'message' => 'Room management is locked because Guest List has been locked'));
				return;
			}
			$this->db->set($type, $type . ' + 1', FALSE);
			$this->db->where('id', $room_id);
			$this->db->update('guest_list_room');
			$this->Booking_Model->Sync_GL_From_Rooms($room['booking_id']);
			$this->Booking_Model->Flag_Autocount_Resync($room['booking_id']);
			echo json_encode(array('success' => true, 'message' => 'Count increased'));
		} else {
			echo json_encode(array('success' => false, 'message' => 'Invalid request'));
		}
	}

	function Subtract_Count()
	{
		if($this->input->post()) {
			$room_id = $this->input->post('room_id');
			$type = $this->input->post('type');
			$allowed = array('adult_count', 'child_count', 'infant_count');
			if(!in_array($type, $allowed)) {
				echo json_encode(array('success' => false, 'message' => 'Invalid type'));
				return;
			}
			$room = $this->_get_room_by_id($room_id);
			if(!$room) {
				echo json_encode(array('success' => false, 'message' => 'Room not found'));
				return;
			}
			if($this->_is_gl_locked($room['booking_id'])) {
				echo json_encode(array('success' => false, 'message' => 'Room management is locked because Guest List has been locked'));
				return;
			}
			$this->db->set($type, $type . ' - 1', FALSE);
			$this->db->where('id', $room_id);
			$this->db->where($type . ' > ', 0);
			$this->db->update('guest_list_room');
			if($this->db->affected_rows() > 0) {
				$this->Booking_Model->Sync_GL_From_Rooms($room['booking_id']);
				$this->Booking_Model->Flag_Autocount_Resync($room['booking_id']);
			}
			echo json_encode(array('success' => true, 'message' => 'Count decreased'));
		} else {
			echo json_encode(array('success' => false, 'message' => 'Invalid request'));
		}
	}

	function Read_Guests()
	{
		$this->load->model('Guest_List_Model');
		$guests = $this->Guest_List_Model->Read_Guests_By_Booking_ID($this->input->get('booking_id'));
		echo json_encode($guests);
	}

	function Delete_Guest()
	{
		$guest_list_id = $this->input->get('guest_list_id');
		$this->db->select('BookingID, Type, guest_list_room_id');
		$this->db->where('GuestListID', $guest_list_id);
		$guest = $this->db->get('guest_list')->row();

		if(!$guest) {
			echo json_encode(array('success' => false, 'message' => 'Guest not found'));
			return;
		}

		if($this->_is_gl_locked($guest->BookingID)) {
			echo json_encode(array('success' => false, 'message' => 'Guest List is locked'));
			return;
		}

		$this->load->model('Guest_List_Model');
		$this->Guest_List_Model->Delete($guest_list_id);

		// Decrease the room pax count for this guest's type
		if(!empty($guest->guest_list_room_id)) {
			$type_map = array('ADULT' => 'adult_count', 'CHILD' => 'child_count', 'INFANT' => 'infant_count');
			if(isset($type_map[$guest->Type])) {
				$col = $type_map[$guest->Type];
				$this->db->set($col, $col . ' - 1', FALSE);
				$this->db->where('id', $guest->guest_list_room_id);
				$this->db->where($col . ' > ', 0);
				$this->db->update('guest_list_room');
			}
		}

		$this->Guest_List_Model->Auto_Assign_Rooms($guest->BookingID);

		$is_submitted = $this->Guest_List_Model->Are_All_Guests_Complete($guest->BookingID) ? 1 : 0;
		$this->Booking_Model->update_by_id($guest->BookingID, array('is_submitted' => $is_submitted));

		echo json_encode(array('success' => true, 'message' => 'Guest successfully deleted'));
	}

	function Update_Guest_Type()
	{
		if(!$this->input->post()) {
			echo json_encode(array('success' => false, 'message' => 'Invalid request'));
			return;
		}

		$guest_list_id = $this->input->post('guest_list_id');
		$type = $this->input->post('type');

		$allowed = array('ADULT', 'CHILD', 'INFANT');
		if(!in_array($type, $allowed)) {
			echo json_encode(array('success' => false, 'message' => 'Invalid type'));
			return;
		}

		$this->db->select('BookingID, Type, guest_list_room_id');
		$this->db->where('GuestListID', $guest_list_id);
		$guest = $this->db->get('guest_list')->row();

		if(!$guest) {
			echo json_encode(array('success' => false, 'message' => 'Guest not found'));
			return;
		}

		if($this->_is_gl_locked($guest->BookingID)) {
			echo json_encode(array('success' => false, 'message' => 'Guest List is locked'));
			return;
		}

		if($guest->Type === $type) {
			echo json_encode(array('success' => true, 'message' => 'No change'));
			return;
		}

		$type_map = array('ADULT' => 'adult_count', 'CHILD' => 'child_count', 'INFANT' => 'infant_count');

		if(!empty($guest->guest_list_room_id) && isset($type_map[$guest->Type]) && isset($type_map[$type])) {
			$old_col = $type_map[$guest->Type];
			$new_col = $type_map[$type];

			$this->db->set($old_col, $old_col . ' - 1', FALSE);
			$this->db->where('id', $guest->guest_list_room_id);
			$this->db->where($old_col . ' > ', 0);
			$this->db->update('guest_list_room');

			$this->db->set($new_col, $new_col . ' + 1', FALSE);
			$this->db->where('id', $guest->guest_list_room_id);
			$this->db->update('guest_list_room');
		}

		$this->db->where('GuestListID', $guest_list_id);
		$this->db->update('guest_list', array('Type' => $type));

		// Guest Type is part of the customer "self guest" snapshot — keep the
		// Customer List column in sync after a room-driven type change.
		$this->load->model('Customer_Model');
		$this->Customer_Model->Refresh_Snapshot_By_Booking($guest->BookingID);

		$this->load->model('Guest_List_Model');
		$this->Guest_List_Model->Auto_Assign_Rooms($guest->BookingID);

		$is_submitted = $this->Guest_List_Model->Are_All_Guests_Complete($guest->BookingID) ? 1 : 0;
		$this->Booking_Model->update_by_id($guest->BookingID, array('is_submitted' => $is_submitted));

		echo json_encode(array('success' => true, 'message' => 'Guest type updated'));
	}

	function Update_Guest_Room()
	{
		if(!$this->input->post()) {
			echo json_encode(array('success' => false, 'message' => 'Invalid request'));
			return;
		}

		$guest_list_id = $this->input->post('guest_list_id');
		$room_id = $this->input->post('room_id');
		$room_id = ($room_id === '' || $room_id === null) ? null : $room_id;

		$this->db->select('BookingID, Type, guest_list_room_id');
		$this->db->where('GuestListID', $guest_list_id);
		$guest = $this->db->get('guest_list')->row();

		if(!$guest) {
			echo json_encode(array('success' => false, 'message' => 'Guest not found'));
			return;
		}

		if($this->_is_gl_locked($guest->BookingID)) {
			echo json_encode(array('success' => false, 'message' => 'Guest List is locked'));
			return;
		}

		if($room_id !== null) {
			$target = $this->_get_room_by_id($room_id);
			if(!$target || $target['booking_id'] != $guest->BookingID) {
				echo json_encode(array('success' => false, 'message' => 'Invalid target room'));
				return;
			}
		}

		$current = $guest->guest_list_room_id !== null ? (string)$guest->guest_list_room_id : '';
		$incoming = $room_id !== null ? (string)$room_id : '';
		if($current === $incoming) {
			echo json_encode(array('success' => true, 'message' => 'No change'));
			return;
		}

		$type_map = array('ADULT' => 'adult_count', 'CHILD' => 'child_count', 'INFANT' => 'infant_count');
		$col = isset($type_map[$guest->Type]) ? $type_map[$guest->Type] : null;

		if($col !== null && !empty($guest->guest_list_room_id)) {
			$this->db->set($col, $col . ' - 1', FALSE);
			$this->db->where('id', $guest->guest_list_room_id);
			$this->db->where($col . ' > ', 0);
			$this->db->update('guest_list_room');
		}

		if($col !== null && $room_id !== null) {
			$this->db->set($col, $col . ' + 1', FALSE);
			$this->db->where('id', $room_id);
			$this->db->update('guest_list_room');
		}

		$this->db->where('GuestListID', $guest_list_id);
		$this->db->update('guest_list', array('guest_list_room_id' => $room_id));

		$this->load->model('Guest_List_Model');
		$is_submitted = $this->Guest_List_Model->Are_All_Guests_Complete($guest->BookingID) ? 1 : 0;
		$this->Booking_Model->update_by_id($guest->BookingID, array('is_submitted' => $is_submitted));

		echo json_encode(array('success' => true, 'message' => 'Guest room updated'));
	}

	private function _get_room_by_id($room_id)
	{
		$this->db->select('id, booking_id');
		$this->db->where('id', $room_id);
		return $this->db->get('guest_list_room')->row_array();
	}

	private function _is_gl_locked($booking_id)
	{
		$this->db->select('LockStatus');
		$this->db->where('BookingID', $booking_id);
		$booking = $this->db->get('booking')->row();
		return $booking && $booking->LockStatus == 'Y';
	}
}
