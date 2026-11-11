<?php
class Booking_Product_Model extends CI_Model
{
	function Read($booking_id = null)
	{
		$this->db->select('BookingProductID, ProductID, ProductCode, Name, Description, Quantity, Price, Total, booking_product.PaymentOutSupplierFull, booking_product.PaymentOutSupplierDeposit, booking_product.disable_checklist_payment_out');
		$this->db->join('booking_product', 'booking_product.BookingID = booking.BookingID', 'left');
        if ($booking_id !== null) {
			$this->db->where('booking.BookingID', $booking_id);
		} elseif (!empty($this->input->get('booking_id'))) {
			$this->db->where('booking.BookingID', $this->input->get('booking_id'));
		} else {
			$this->db->where('Token', $this->input->get('token'));
		}
		$this->db->where('booking_product.Status', 'Y');
        return $this->db->get('booking')->result();
	}

	function Read_Ordered($booking_id, $product_sequence)
	{
		$booking_products = $this->Read($booking_id);
		if (empty($product_sequence)) {
			return $booking_products;
		}

		$products_by_id = array_column($booking_products, null, 'BookingProductID');
		$ordered_products = [];
		foreach (explode(',', $product_sequence) as $product_id) {
			$product_id = trim($product_id);
			if (isset($products_by_id[$product_id])) {
				$ordered_products[$product_id] = $products_by_id[$product_id];
			}
		}

		return array_values($ordered_products);
	}

	function Read_Booking_Products($booking_id)
	{
		$this->db->select('BookingProductID');
		$this->db->where('BookingID', $booking_id);
		$this->db->where('Status', 'Y');
        return $this->db->get('booking_product')->result();
	}
	
	function Read_Last_Booking_Product_ID() {
		$this->db->select('BookingProductID');
		$this->db->limit(1);
		$this->db->order_by('BookingProductID', 'DESC');
		return $this->db->get('booking_product')->row()->BookingProductID;
	}
	
	function Create($booking_product, $booking_id)
	{
		$this->db->insert_batch('booking_product', json_decode(json_encode($booking_product)));
		$this->db->set('BookingID', $booking_id);
		$this->db->where('BookingID', null);
		$this->db->update('booking_product');

		$this->Update_Description($booking_id);
	}

	function Update($booking_product)
	{
		$this->db->update_batch('booking_product', json_decode(json_encode($booking_product)), 'BookingProductID');

		$this->Update_Description($this->input->post('booking_id'));
	}
	
	function Update_Description($booking_id)
	{
		$this->db->set('Description', null);
		$this->db->where('BookingID', $booking_id);
		$this->db->where('Description', '');
		$this->db->update('booking_product');
	}

	function Recompute_Subtotal($booking_id)
	{
		$this->db->select_sum('Total');
		$this->db->where('BookingID', $booking_id);
		$this->db->where('Status', 'Y');
		$row = $this->db->get('booking_product')->row();
		$subtotal = !empty($row) && $row->Total !== null ? $row->Total : 0;

		$this->db->set('Subtotal', $subtotal);
		$this->db->where('BookingID', $booking_id);
		$this->db->update('booking');
	}

	function Cleanup_Supplier_Dates($booking_id)
	{
		$this->db->set('PaymentOutSupplierFull', null);
		$this->db->where('BookingID', $booking_id);
		$this->db->where("CAST(`PaymentOutSupplierFull` AS CHAR) = '0000-00-00'", null, false);
		$this->db->update('booking_product');

		$this->db->set('PaymentOutSupplierDeposit', null);
		$this->db->where('BookingID', $booking_id);
		$this->db->where("CAST(`PaymentOutSupplierDeposit` AS CHAR) = '0000-00-00'", null, false);
		$this->db->update('booking_product');
	}
}