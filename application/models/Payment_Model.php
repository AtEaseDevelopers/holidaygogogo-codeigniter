<?php
class Payment_Model extends CI_Model
{
	function Count_Upcoming_Due()
	{
		if(intval(date('H')) < 14) {
			return 0;
		}
		$this->db->where('Status', 'P');
		$this->db->where('Deadline', date('Y-m-d'));
		return $this->db->count_all_results('payment');
	}

	function Read_Payment()
	{
		$this->db->select('PaymentID, payment.BookingID, payment.SupplierID, payment.BookingProductID, Date, Type, Currency, ForeignCurrency, Credit, ReferenceNumber, BankSlip, Debit, Deadline, QuotationNumber, Quotation, InvoiceNumber, Invoice, payment.Bank, payment.BankAccount, payment.BankHolder, DebitRemark, PaymentRemark, payment.Status, Remark, BookingNumber, ReservationNumber, Customer, payment.AutocountSyncAction, payment.AutocountSyncStatus, payment.AutocountSyncMessage, payment.AutocountReferenceNumber');
		$this->db->join('booking', 'booking.BookingID = payment.BookingID', 'left');
		$this->db->where('PaymentID', $this->input->get('payment_id'));
		return $this->db->get('payment')->row_array();
	}
	
	function Read_Payments1($limit = null)
	{
		$this->db->select('PaymentID, payment.BookingID, payment.SupplierID, Date, Type, Credit, ReferenceNumber, Debit, Deadline, payment.BankHolder, payment.Status, BookingNumber, ReservationNumber, Customer, StartDate, EndDate, NetTotal, Token, admin.Name As SalesAgent, supplier.Name As Supplier,  payment.AutocountSyncStatus, payment.AutocountSyncMessage, payment.AutocountSyncAction,payment.AutocountReferenceNumber');
		$this->db->join('payment', 'payment.BookingID = booking.BookingID', 'left');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('supplier', 'supplier.SupplierID = payment.SupplierID', 'left');
		if($this->session->userdata('level') == 20) {
			$this->db->where('SalesAgent', $this->session->userdata('admin_id'));
		}
		if(!empty($this->input->get('supplier'))) {
			$this->db->where('payment.SupplierID', $this->input->get('supplier'));
		}
		if(!empty($this->input->get('transaction_date'))) {
			$transaction_date = explode(' - ', $this->input->get('transaction_date'));
			$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $transaction_date[0])));
			$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $transaction_date[1])));
			$this->db->where('Date >=', $start_date);
			$this->db->where('Date <=', $end_date);
		}
		if(!empty($this->input->get('payment_type'))) {
			$this->db->where('Type', $this->input->get('payment_type'));
		}
		if(!empty($this->input->get('transaction_type'))) {
			if($this->input->get('transaction_type') == 'PAYMENT IN') {
				$this->db->where('Credit !=', 0.00);
			} else {
				$this->db->where('Credit', 0.00);
			}
		}
		if(!empty($this->input->get('reference_number'))) {
			$this->db->where('ReferenceNumber', $this->input->get('reference_number'));
		}
		if(!empty($this->input->get('payment_deadline'))) {
			$payment_deadline = explode(' - ', $this->input->get('payment_deadline'));
			$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $payment_deadline[0])));
			$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $payment_deadline[1])));
			$this->db->where('Deadline >=', $start_date);
			$this->db->where('Deadline <=', $end_date);
		}
		if(!empty($this->input->get('quotation_number'))) {
			$this->db->where('QuotationNumber', $this->input->get('quotation_number'));
		}
		if(!empty($this->input->get('invoice_number'))) {
			$this->db->where('InvoiceNumber', $this->input->get('invoice_number'));
		}
		if(!empty($this->input->get('bank'))) {
			$this->db->where('payment.Bank', $this->input->get('bank'));
		}
		if(!empty($this->input->get('bank_account'))) {
			$this->db->where('payment.BankAccount', $this->input->get('bank_account'));
		}
		if(!empty($this->input->get('bank_holder'))) {
			$this->db->where('payment.BankHolder', $this->input->get('bank_holder'));
		}
		if(!empty($this->input->get('status'))) {
			$statuses = explode(',', $this->input->get('status'));
			$this->db->where_in('payment.Status', $statuses);
		} else {
			if(strpos($_SERVER['REQUEST_URI'], '?') == false) {
				$this->db->where('payment.Status', 'P');
			}
		}
		if(!empty($this->input->get('booking_number'))) {
			$this->db->where('BookingNumber', $this->input->get('booking_number'));
		}
		if(!empty($this->input->get('customer'))) {
			$this->db->where('Customer', $this->input->get('customer'));
		}
		if(!empty($this->input->get('travel_date'))) {
			$travel_date = explode(' - ', $this->input->get('travel_date'));
			$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $travel_date[0])));
			$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $travel_date[1])));
			$this->db->where("((`StartDate` <= '".$start_date."' AND `EndDate` >= '".$end_date."') OR (`StartDate` >= '".$start_date."' AND `StartDate` <= '".$end_date."') OR (`EndDate` >= '".$start_date."' AND `EndDate` <= '".$end_date."'))");
		}
		if(!empty($this->input->get('sales_agent'))) {
			$this->db->where('SalesAgent', $this->input->get('sales_agent'));
		}
		if(!empty($limit)) {
			$this->db->limit($limit);
		}
		else if($this->input->get('status') == 'Y') {
			$this->db->limit(100);
		}
		$this->db->where('payment.Status !=', 'N');
		$this->db->order_by('Date DESC');
		return $this->db->get('booking')->result();
	}

	function Read_Payments2()
	{
		$this->db->select('Date, Type, ForeignCurrency, Credit, ReferenceNumber, Debit, Deadline, QuotationNumber, InvoiceNumber, payment.Bank, payment.BankAccount, payment.BankHolder, PaymentRemark, BookingNumber, ReservationNumber, Customer, StartDate, EndDate, admin.Name As SalesAgent, category.Name As Destination, supplier.Name As Supplier, payment.AutocountReferenceNumber, payment.Status');
		$this->db->join('payment', 'payment.BookingID = booking.BookingID', 'left');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->join('supplier', 'supplier.SupplierID = payment.SupplierID', 'left');
		if($this->session->userdata('level') == 20) {
			$this->db->where('SalesAgent', $this->session->userdata('admin_id'));
		}
		if(!empty($this->input->get('supplier'))) {
			$this->db->where('payment.SupplierID', $this->input->get('supplier'));
		}
		if(!empty($this->input->get('transaction_date'))) {
			$transaction_date = explode(' - ', $this->input->get('transaction_date'));
			$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $transaction_date[0])));
			$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $transaction_date[1])));
			$this->db->where('Date >=', $start_date);
			$this->db->where('Date <=', $end_date);
		}
		if(!empty($this->input->get('payment_type'))) {
			$this->db->where('Type', $this->input->get('payment_type'));
		}
		if(!empty($this->input->get('transaction_type'))) {
			if($this->input->get('transaction_type') == 'PAYMENT IN') {
				$this->db->where('Credit !=', 0.00);
			} else {
				$this->db->where('Credit', 0.00);
			}
		}
		if(!empty($this->input->get('reference_number'))) {
			$this->db->where('ReferenceNumber', $this->input->get('reference_number'));
		}
		if(!empty($this->input->get('payment_deadline'))) {
			$payment_deadline = explode(' - ', $this->input->get('payment_deadline'));
			$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $payment_deadline[0])));
			$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $payment_deadline[1])));
			$this->db->where('Deadline >=', $start_date);
			$this->db->where('Deadline <=', $end_date);
		}
		if(!empty($this->input->get('quotation_number'))) {
			$this->db->where('QuotationNumber', $this->input->get('quotation_number'));
		}
		if(!empty($this->input->get('invoice_number'))) {
			$this->db->where('InvoiceNumber', $this->input->get('invoice_number'));
		}
		if(!empty($this->input->get('bank'))) {
			$this->db->where('payment.Bank', $this->input->get('bank'));
		}
		if(!empty($this->input->get('bank_account'))) {
			$this->db->where('payment.BankAccount', $this->input->get('bank_account'));
		}
		if(!empty($this->input->get('bank_holder'))) {
			$this->db->where('payment.BankHolder', $this->input->get('bank_holder'));
		}
		if(!empty($this->input->get('status'))) {
			$statuses = explode(',', $this->input->get('status'));
			$this->db->where_in('payment.Status', $statuses);
		} else {
			if(strpos($_SERVER['REQUEST_URI'], '?') == false) {
				$this->db->where('payment.Status', 'P');
			}
		}
		if(!empty($this->input->get('booking_number'))) {
			$this->db->where('BookingNumber', $this->input->get('booking_number'));
		}
		if(!empty($this->input->get('customer'))) {
			$this->db->where('Customer', $this->input->get('customer'));
		}
		if(!empty($this->input->get('travel_date'))) {
			$travel_date = explode(' - ', $this->input->get('travel_date'));
			$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $travel_date[0])));
			$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $travel_date[1])));
			$this->db->where("((`StartDate` <= '".$start_date."' AND `EndDate` >= '".$end_date."') OR (`StartDate` >= '".$start_date."' AND `StartDate` <= '".$end_date."') OR (`EndDate` >= '".$start_date."' AND `EndDate` <= '".$end_date."'))");
		}
		if(!empty($this->input->get('sales_agent'))) {
			$this->db->where('SalesAgent', $this->input->get('sales_agent'));
		}
		$this->db->where('payment.Status !=', 'N');
		$this->db->order_by('Date DESC');
		return $this->db->get('booking')->result();
	}

	function Read_BC_Payments()
	{
		$this->db->select('Date, Type, Credit, ReferenceNumber, Debit, Deadline, QuotationNumber, InvoiceNumber, payment.Bank, payment.BankAccount, payment.BankHolder, DebitRemark, PaymentRemark, payment.Status, Remark, supplier.Name As Supplier');
		$this->db->join('supplier', 'supplier.SupplierID = payment.SupplierID', 'left');
		$this->db->where('payment.BookingID', $this->input->get('booking_id'));
		$this->db->where('payment.Status !=', 'N');
		return $this->db->get('payment')->result();
	}

	function Read_Booking()
	{
		$this->db->select('ReservationNumber, DepositDeadline, FullPaymentDeadline, AdditionalPaymentDeadline, StartDate, EndDate, BookingRemark, NetTotal, Token, LockStatus, AfterSalesService, booking.Status, admin.Name As SalesAgentName, category.Name As DestinationName');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->where('booking.BookingID', $this->input->get('booking_id'));
		return $this->db->get('booking')->row_array();
	}

	function Read_Bookings()
	{
		$this->db->select('booking.BookingID, BookingNumber, Customer');
		if($this->session->userdata('level') == 20) {
			$this->db->where('SalesAgent', $this->session->userdata('admin_id'));
			$this->db->where('AfterSalesService', 'PENDING');
		}
		$this->db->where('CancelStatus', 'N');
		$this->db->where('booking.Status !=', 'N');
		if(!in_array($this->session->userdata('level'), [10, 30])) {
			$this->db->where("(booking.Status != 'Y' OR booking.AfterSalesService = 'PENDING')", null, false);
		}
		$this->db->order_by('booking.BookingID', 'DESC');
		return $this->db->get('booking')->result();
	}

	function Read_Admins()
	{
		$this->db->select('AdminID, Name, Status');
		$this->db->where('AdminID !=', 8);
		$this->db->where('Level !=', '30');
		$this->db->where_in('Status', array('Y', 'D'));
		$this->db->order_by('Status', 'ASC');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('admin')->result();
	}

	function Read_Suppliers()
	{
		$this->db->select('SupplierID, Name');
		$this->db->where('Status', 'Y');
		$this->db->order_by('Name', 'ASC');
		return $this->db->get('supplier')->result();
	}

	function Read_Country_Codes()
	{
		$this->db->select('CountryCodeID, Country, CurrencyCode');
		$this->db->where('Status', 'Y');
		$this->db->order_by('Country', 'ASC');
		return $this->db->get('country_code')->result();
	}

	function Read_Booking_Products_For_Payment($booking_id)
	{
		$this->db->select('bp.BookingProductID, bp.ProductID, p.Name, p.ProductCode');
		$this->db->from('booking_product bp');
		$this->db->join('product p', 'p.ProductID = bp.ProductID', 'left');
		$this->db->where('bp.BookingID', $booking_id);
		$this->db->where('bp.Status', 'Y');
		$this->db->where('bp.disable_checklist_payment_out', 0);
		return $this->db->get()->result();
	}

	function Read_Received_Payments($booking_id) {
		$this->db->select('Type, Credit, Debit');
		$this->db->where('BookingID', $booking_id);
		$this->db->where_in('Type', payment_received_credit_types());
		$this->db->where('Status', 'Y');
		return $this->db->get('payment')->result();
	}
	
	function Read_Approved_Payments($booking_id) {
		$this->db->select('Date, Type, Credit, ReferenceNumber, Status');
		$this->db->where('BookingID', $booking_id);
		$this->db->where('Status', 'Y');
		$this->db->where('Credit >', 0);
		$this->db->order_by('Date', 'ASC');
		return $this->db->get('payment')->result();
	}
	
	function Read_Date($payment_id) {
		$this->db->select('Date');
		$this->db->where('PaymentID', $payment_id);
		return $this->db->get('payment')->row()->Date;
	}

	function Read_Type($booking_id) {
		$this->db->where('BookingID', $booking_id);
        $this->db->where('Type', 'FULL');
		$this->db->where('Status', 'Y');
		if($this->db->get('payment')->row()) {
			return true;
		} else {
			return false;
		}
	}

	function Read_Reference_Number($payment_id) { 
		$this->db->select('ReferenceNumber');
		$this->db->where('PaymentID', $payment_id);
		return $this->db->get('payment')->row()->ReferenceNumber;
	}

	function Read_Deadline($payment_id) {
		$this->db->select('Deadline');
		$this->db->where('PaymentID', $payment_id);
		return $this->db->get('payment')->row()->Deadline;
	}

	function Read_Status($payment_id) {
		$this->db->select('Status');
		$this->db->where('PaymentID', $payment_id);
		return $this->db->get('payment')->row()->Status;
	}
	
	function Create($count)
	{
		$array = array(
			'BookingID' => $this->input->post('booking'),
			'SupplierID' => (payment_type_uses_supplier($this->input->post('debit_type-' . $count)) || payment_type_uses_supplier($this->input->post('credit_type-' . $count))) ? $this->input->post('supplier-' . $count) : null,
			'BookingProductID' => in_array($this->input->post('debit_type-' . $count), array('SUPPLIER PAYMENT (DEPOSIT)', 'SUPPLIER PAYMENT (FULL)', 'SUPPLIER PAYMENT (ADDITIONAL)')) ? $this->input->post('booking_product-' . $count) : null,
			'Date' => $this->input->post('credit_type-' . $count) == 'DEPOSIT' || $this->input->post('credit_type-' . $count) == 'FULL' || $this->input->post('credit_type-' . $count) == 'SUPPLIER REFUND' || $this->input->post('credit_type-' . $count) == 'ADDITIONAL PAYMENT' || $this->input->post('credit_type-' . $count) == 'AGENT COMMISSION FROM SUPPLIER' ? date('Y-m-d', strtotime(str_replace('/', '-', $this->input->post('transaction_date-' . $count)))) : null,
			'Type' => $this->input->post('credit_type-' . $count) == 'DEPOSIT' || $this->input->post('credit_type-' . $count) == 'FULL' || $this->input->post('credit_type-' . $count) == 'SUPPLIER REFUND' || $this->input->post('credit_type-' . $count) == 'ADDITIONAL PAYMENT' || $this->input->post('credit_type-' . $count) == 'AGENT COMMISSION FROM SUPPLIER' ? $this->input->post('credit_type-' . $count) : $this->input->post('debit_type-' . $count),
			'Credit' => $this->input->post('credit_type-' . $count) == 'DEPOSIT' || $this->input->post('credit_type-' . $count) == 'FULL' || $this->input->post('credit_type-' . $count) == 'SUPPLIER REFUND' || $this->input->post('credit_type-' . $count) == 'ADDITIONAL PAYMENT' || $this->input->post('credit_type-' . $count) == 'AGENT COMMISSION FROM SUPPLIER' ? str_replace(',', '', $this->input->post('credit-' . $count)) : 0.00,
			'ReferenceNumber' => !empty($this->input->post('reference_number-' . $count)) ? strtoupper($this->input->post('reference_number-' . $count)) : null,
			'Debit' => ($this->input->post('debit_type-' . $count) == 'SUPPLIER PAYMENT (DEPOSIT)' || $this->input->post('debit_type-' . $count) == 'SUPPLIER PAYMENT (FULL)' || $this->input->post('debit_type-' . $count) == 'SUPPLIER PAYMENT (ADDITIONAL)' || $this->input->post('debit_type-' . $count) == 'CUSTOMER REFUND' || $this->input->post('debit_type-' . $count) == 'ONE-TIME PAYMENT' || $this->input->post('debit_type-' . $count) == 'AGENT COMMISSION' || $this->input->post('debit_type-' . $count) == 'BANK CHARGES' || $this->input->post('debit_type-' . $count) == 'AGENT COMMISSION FROM SUPPLIER' || $this->input->post('debit_type-' . $count) == 'CREDIT CARD CHARGES') && !empty($this->input->post('debit-' . $count)) ? str_replace(',', '', $this->input->post('debit-' . $count)) : 0.00,
			'Deadline' => $this->input->post('debit_type-' . $count) == 'SUPPLIER PAYMENT (DEPOSIT)' || $this->input->post('debit_type-' . $count) == 'SUPPLIER PAYMENT (FULL)' || $this->input->post('debit_type-' . $count) == 'SUPPLIER PAYMENT (ADDITIONAL)' || $this->input->post('debit_type-' . $count) == 'CUSTOMER REFUND' || $this->input->post('debit_type-' . $count) == 'ONE-TIME PAYMENT' || $this->input->post('debit_type-' . $count) == 'AGENT COMMISSION' || $this->input->post('debit_type-' . $count) == 'BANK CHARGES' || $this->input->post('debit_type-' . $count) == 'AGENT COMMISSION FROM SUPPLIER' || $this->input->post('debit_type-' . $count) == 'CREDIT CARD CHARGES' ? date('Y-m-d', strtotime(str_replace('/', '-', $this->input->post('payment_deadline-' . $count)))) : null,
			'QuotationNumber' => in_array($this->input->post('debit_type-' . $count), array('SUPPLIER PAYMENT (DEPOSIT)', 'SUPPLIER PAYMENT (FULL)', 'SUPPLIER PAYMENT (ADDITIONAL)')) && !empty($this->input->post('quotation_number-' . $count)) ? strtoupper($this->input->post('quotation_number-' . $count)) : null,
			'InvoiceNumber' => in_array($this->input->post('debit_type-' . $count), array('SUPPLIER PAYMENT (DEPOSIT)', 'SUPPLIER PAYMENT (FULL)', 'SUPPLIER PAYMENT (ADDITIONAL)')) && !empty($this->input->post('invoice_number-' . $count)) ? strtoupper($this->input->post('invoice_number-' . $count)) : null,
			'Currency' => ($this->input->post('debit_type-' . $count) == 'SUPPLIER PAYMENT (DEPOSIT)' || $this->input->post('debit_type-' . $count) == 'SUPPLIER PAYMENT (FULL)' || $this->input->post('debit_type-' . $count) == 'SUPPLIER PAYMENT (ADDITIONAL)' || $this->input->post('debit_type-' . $count) == 'CUSTOMER REFUND' || $this->input->post('debit_type-' . $count) == 'ONE-TIME PAYMENT' || $this->input->post('debit_type-' . $count) == 'AGENT COMMISSION' || $this->input->post('debit_type-' . $count) == 'BANK CHARGES' || $this->input->post('debit_type-' . $count) == 'AGENT COMMISSION FROM SUPPLIER' || $this->input->post('debit_type-' . $count) == 'CREDIT CARD CHARGES') && !empty($this->input->post('currency_code-' . $count)) ? $this->input->post('currency_code-' . $count) : null,
			'ForeignCurrency' => ($this->input->post('debit_type-' . $count) == 'SUPPLIER PAYMENT (DEPOSIT)' || $this->input->post('debit_type-' . $count) == 'SUPPLIER PAYMENT (FULL)' || $this->input->post('debit_type-' . $count) == 'SUPPLIER PAYMENT (ADDITIONAL)' || $this->input->post('debit_type-' . $count) == 'CUSTOMER REFUND' || $this->input->post('debit_type-' . $count) == 'ONE-TIME PAYMENT' || $this->input->post('debit_type-' . $count) == 'AGENT COMMISSION' || $this->input->post('debit_type-' . $count) == 'BANK CHARGES' || $this->input->post('debit_type-' . $count) == 'AGENT COMMISSION FROM SUPPLIER' || $this->input->post('debit_type-' . $count) == 'CREDIT CARD CHARGES') && !empty($this->input->post('foreign_currency-' . $count)) ? str_replace(',', '', $this->input->post('foreign_currency-' . $count)) : 0.00,
			'Bank' => $this->input->post('debit_type-' . $count) == 'CUSTOMER REFUND' || $this->input->post('debit_type-' . $count) == 'ONE-TIME PAYMENT' || $this->input->post('debit_type-' . $count) == 'AGENT COMMISSION' || $this->input->post('debit_type-' . $count) == 'BANK CHARGES' || $this->input->post('debit_type-' . $count) == 'CREDIT CARD CHARGES' ? strtoupper($this->input->post('bank-' . $count)) : null,
			'BankAccount' => $this->input->post('debit_type-' . $count) == 'CUSTOMER REFUND' || $this->input->post('debit_type-' . $count) == 'ONE-TIME PAYMENT' || $this->input->post('debit_type-' . $count) == 'AGENT COMMISSION' || $this->input->post('debit_type-' . $count) == 'BANK CHARGES' || $this->input->post('debit_type-' . $count) == 'CREDIT CARD CHARGES' ? strtoupper($this->input->post('bank_account-' . $count)) : null,
			'BankHolder' => $this->input->post('debit_type-' . $count) == 'CUSTOMER REFUND' || $this->input->post('debit_type-' . $count) == 'ONE-TIME PAYMENT' || $this->input->post('debit_type-' . $count) == 'AGENT COMMISSION' || $this->input->post('debit_type-' . $count) == 'BANK CHARGES' || $this->input->post('debit_type-' . $count) == 'CREDIT CARD CHARGES' ? strtoupper($this->input->post('bank_holder-' . $count)) : null,
			'DebitRemark' => ($this->input->post('debit_type-' . $count) == 'CUSTOMER REFUND' || $this->input->post('debit_type-' . $count) == 'ONE-TIME PAYMENT' || $this->input->post('debit_type-' . $count) == 'AGENT COMMISSION' || $this->input->post('debit_type-' . $count) == 'BANK CHARGES' || $this->input->post('debit_type-' . $count) == 'CREDIT CARD CHARGES') && !empty($this->input->post('remark-' . $count)) ? strtoupper($this->input->post('remark-' . $count)) : null,
			'PaymentRemark' => !empty($this->input->post('payment_remark-' . $count)) ? strtoupper($this->input->post('payment_remark-' . $count)) : null,
			'InsertBy' => $this->session->userdata('admin_id'),
			'InsertDate' => date('Y-m-d H:i:s')
		);
		$this->db->insert('payment', $array);
		$payment_id = $this->db->insert_id();

		$this->db->select('Date, Type, Credit, ReferenceNumber, Debit, Deadline, QuotationNumber, InvoiceNumber, payment.Bank, payment.BankAccount, payment.BankHolder, DebitRemark, BookingNumber, StartDate, EndDate, Subtotal, category.Name As Destination, admin.Name As SalesAgent, supplier.Name As Supplier');
		$this->db->join('booking', 'booking.BookingID = payment.BookingID', 'left');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('category', 'category.CategoryID = booking.Destination', 'left');
		$this->db->join('supplier', 'supplier.SupplierID = payment.SupplierID', 'left');
		$this->db->where('PaymentID', $payment_id);
		$payment = $this->db->get('payment')->row_array();
		if(!empty($payment['StartDate']) && !empty($payment['EndDate'])) {
			$payment['TravelDate'] = strtoupper(date('j M', strtotime($payment['StartDate'])) . ' - ' . date('j M Y', strtotime($payment['EndDate'])));
		} else {
			$payment['TravelDate'] = '-';
		}
		$payment['Date'] = empty($payment['Date']) ? '-' : strtoupper(date('j M Y', strtotime($payment['Date'])));
		$payment['ReferenceNumber'] = empty($payment['ReferenceNumber']) ? '-' : $payment['ReferenceNumber'];
		if($payment['Credit'] != 0.00) {
			$payment['Credit'] = 'RM ' . number_format($payment['Credit'], 2, '.', ',');
			$text = urlencode('New Payment Record Successfully Created' . "\n\n" . 'Booking Number : ' . "\n" . $payment['BookingNumber'] . "\n\n" . 'Travel Date : ' . "\n" . $payment['TravelDate'] . "\n\n" . 'Destination : ' . "\n" . $payment['Destination'] . "\n\n" . 'Sales Agent : ' . "\n" . $payment['SalesAgent'] . "\n\n" . 'Transaction Date : ' . "\n" . $payment['Date'] . "\n\n" . 'Payment In : ' . "\n" . $payment['Credit'] . "\n\n" . 'Payment Type : ' . "\n" . $payment['Type'] . "\n\n" . 'Reference Number : ' . "\n" . $payment['ReferenceNumber']);
		} else {
			$payment['Debit'] = 'RM ' . number_format($payment['Debit'], 2, '.', ',');
			$payment['Deadline'] = strtoupper(date('j M Y', strtotime($payment['Deadline'])));
			$payment['DebitRemark'] = empty($payment['DebitRemark']) ? '-' : $payment['DebitRemark'];
			if(in_array($payment['Type'], array('SUPPLIER PAYMENT (DEPOSIT)', 'SUPPLIER PAYMENT (FULL)', 'SUPPLIER PAYMENT (ADDITIONAL)'))) {
				$payment['QuotationNumber'] = empty($payment['QuotationNumber']) ? '-' : $payment['QuotationNumber'];
				$payment['InvoiceNumber'] = empty($payment['InvoiceNumber']) ? '-' : $payment['InvoiceNumber'];
				$text = urlencode('New Payment Record Successfully Created' . "\n\n" . 'Booking Number : ' . "\n" . $payment['BookingNumber'] . "\n\n" . 'Travel Date : ' . "\n" . $payment['TravelDate'] . "\n\n" . 'Destination : ' . "\n" . $payment['Destination'] . "\n\n" . 'Sales Agent : ' . "\n" . $payment['SalesAgent'] . "\n\n" . 'Transaction Date : ' . "\n" . $payment['Date'] . "\n\n" . 'Payment Out : ' . "\n" . $payment['Debit'] . "\n\n" . 'Payment Type : ' . "\n" . $payment['Type'] . "\n\n" . 'Supplier : ' . "\n" . $payment['Supplier'] . "\n\n" . 'Payment Deadline : ' . "\n" . $payment['Deadline'] . "\n\n" . 'Quotation Number : ' . "\n" . $payment['QuotationNumber'] . "\n\n" . 'Invoice Number : ' . "\n" . $payment['InvoiceNumber']);
			} else {
				$text = urlencode('New Payment Record Successfully Created' . "\n\n" . 'Booking Number : ' . "\n" . $payment['BookingNumber'] . "\n\n" . 'Travel Date : ' . "\n" . $payment['TravelDate'] . "\n\n" . 'Destination : ' . "\n" . $payment['Destination'] . "\n\n" . 'Sales Agent : ' . "\n" . $payment['SalesAgent'] . "\n\n" . 'Transaction Date : ' . "\n" . $payment['Date'] . "\n\n" . 'Payment Out : ' . "\n" . $payment['Debit'] . "\n\n" . 'Payment Type : ' . "\n" . $payment['Type'] . "\n\n" . 'Payment Deadline : ' . "\n" . $payment['Deadline'] . "\n\n" . 'Bank : ' . "\n" . $payment['Bank'] . "\n\n" . 'Bank Account : ' . "\n" . $payment['BankAccount'] . "\n\n" . 'Bank Holder : ' . "\n" . $payment['BankHolder'] . "\n\n" . 'Remark : ' . "\n" . $payment['DebitRemark']);
			}
		}

		// Send Telegram notification only if APP_ENV is 'prod'
		$this->load->helper('utils');
		$app_env = get_app_env();
		if ($app_env === 'prod') {
			$telegram_ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
			@file_get_contents('https://api.telegram.org/bot7521016286:AAEMDyjd789UEHBH5LK4xfBzIzY9TZ80tCg/sendMessage?chat_id=-1002546036574&text=' . $text, false, $telegram_ctx);
		}

		return $payment_id;
	}

	function Create_Payment_Log($column, $current_data, $new_data, $payment_id)
	{
		$array = array(
			'PaymentID' => $payment_id,
			'Column' => $column,
			'CurrentData' => $current_data,
			'NewData' => $new_data,
			'InsertBy' => $this->session->admin_id,
			'InsertDate' => date('Y-m-d H:i:s')
		);
		$this->db->insert('payment_log', $array);
	}

	function Create_Payment_Log2()
	{
		$array = array(
			'PaymentID' => $this->input->get('payment_id'),
			'Column' => 'Status',
			'CurrentData' => $this->input->get('status'),
			'NewData' => 'N',
			'InsertBy' => $this->session->admin_id,
			'InsertDate' => date('Y-m-d H:i:s')
		);
		$this->db->insert('payment_log', $array);
	}
	
	function Update($payment)
	{
		$this->db->update_batch('payment', $payment, 'PaymentID');
	}

	function Update_Date($date, $payment_id)
	{
		$array = array(
			'Date' => $date,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		$this->db->where('PaymentID', $payment_id);
		$this->db->update('payment', $array);
	}

	function Update_Reference_Number($reference_number, $payment_id)
	{
		$array = array(
			'ReferenceNumber' => $reference_number,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		$this->db->where('PaymentID', $payment_id);
		$this->db->update('payment', $array);
	}

	function Update_Deadline($deadline, $payment_id)
	{
		$array = array(
			'Deadline' => $deadline,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		$this->db->where('PaymentID', $payment_id);
		$this->db->update('payment', $array);
	}

	function Update_File($column, $value, $payment_id)
	{
		$array = array(
			$column => $value['file_name'],
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		$this->db->where('PaymentID', $payment_id);
		$this->db->update('payment', $array);
	}

	function Update_Status($status, $payment_id)
	{
		$array = array(
			'Status' => $status,
			'UpdateBy' => $this->session->userdata('admin_id'),
			'UpdateDate' => date('Y-m-d H:i:s')
		);
		$this->db->where('PaymentID', $payment_id);
		$this->db->update('payment', $array);
	}
	
	function Detect()
	{
		$this->db->where('PaymentID !=', $this->input->post('payment_id'));
		$this->db->where('BookingID', $this->input->post('booking_id'));
		$this->db->where('Type', 'FULL');
		$this->db->where_in('Status', array('Y', 'P'));
		if($this->db->get('payment')->row()) {
			return true;
		} else {
			return false;
		}
	}

	function getAllBookingsWithPayment($payment_id = null)
	{
		// Get all booking columns
		$bookingCols = $this->db->list_fields('booking');
		$bookingCols = array_map(function($col) {
			return "booking.`$col`";
		}, $bookingCols);

		// Get all payment columns, alias with payment_ prefix
		$paymentCols = $this->db->list_fields('payment');
		$paymentCols = array_map(function($col) {
			return "payment.`$col` AS payment_$col";
		}, $paymentCols);

		// Merge both sets of columns
		$allCols = array_merge($bookingCols, $paymentCols);
		$this->db->select(implode(', ', $allCols), false);

		// Main query
		$this->db->from('booking');
		$this->db->join('payment', 'payment.PaymentID = booking.PaymentID', 'left');

		// Optional filter by PaymentID
		if (!is_null($payment_id) && $payment_id !== '') {
			$this->db->where('payment.PaymentID', $payment_id);
		}

		$this->db->order_by('booking.BookingID', 'ASC');

		$query = $this->db->get();
		return $query->result();
	}

	function getPaymentById($payment_id)
	{
		$this->db->select('*');
		$this->db->from('payment');
		$this->db->where('PaymentID', $payment_id);
		$query = $this->db->get();

		return $query->num_rows() > 0 ? $query->row() : null;
	}

	function getSupplierByPaymentId($payment_id)
	{
		$supplierCols = $this->db->list_fields('supplier');
		$supplierCols = array_map(fn($col) => "supplier.`$col` AS supplier_$col", $supplierCols);

		$this->db->select(implode(', ', $supplierCols), false);
		$this->db->from('payment');
		$this->db->join('supplier', 'supplier.SupplierID = payment.SupplierID', 'left');
		$this->db->where('payment.PaymentID', $payment_id);

		$query = $this->db->get();
		return $query->num_rows() > 0 ? $query->row() : null;
	}

	public function find($payment_id)
    {
        return $this->db->get_where('payment', ['PaymentID' => $payment_id])->row();
    }
	
    public function update_by_id($payment_id, $data = [])
    {
        if (empty($data)) return false;

        return $this->db
            ->where('PaymentID', $payment_id)
            ->update('payment', $data);
    }

	function getAllPaymentsWithBookingAndSupplier($payment_id = null, $deleted_payment = false)
	{
		$this->db->select('payment.*', false);

		$this->db->from('payment'); // ✅ Base table

		// Join with booking
		$this->db->join('booking', 'booking.BookingID = payment.BookingID', 'left');
		$this->db->select([
			'booking.BookingID',
			'booking.Customer',
			'booking.CustomerID',
			'booking.BookingNumber',
			'booking.InsertDate',
			'booking.SalesAgent',
			'booking.ReservationNumber',
			'booking.StartDate',
			'booking.EndDate'
		], false);

		// special to retrict only have customer code can sync -- 2025 Jan 16
		$this->db->join('customer', 'customer.CustomerID = booking.CustomerID', 'left');
		$this->db->select([
			'customer.CustomerCode'
		], false);

		$this->db->where('customer.CustomerCode IS NOT NULL');
		$this->db->where('customer.CustomerCode <>', '');

		// Join with supplier
		$this->db->join('supplier', 'supplier.SupplierID = payment.SupplierID', 'left');
		$this->db->select([
			'supplier.SupplierID',
			'supplier.Name as supplier_name',
			'supplier.PrimaryEmail',
		], false);

		if (!is_null($payment_id) && $payment_id !== '') {
			$this->db->where('payment.PaymentID', $payment_id);
		}

		$this->load->helper('autocount');
		$config = get_autocount_config();

		$statuses = !empty($config['payment_sync_autocount_status'])
			? $config['payment_sync_autocount_status']
			: ['P'];

		$titles = !empty($config['payment_sync_status'])
			? $config['payment_sync_status']
			: ['Y'];

		$this->db->where_in('payment.AutocountSyncStatus', $statuses);
		if ($deleted_payment == false) {
			$this->db->where_in('payment.Status', $titles);
			$this->db->where('payment.AutocountSyncAction IS NOT NULL');
		} else {
			$this->db->where('payment.AutocountSyncAction', 'D');			
		}
		if (!empty($config['payment_cutoff_date'])) {
			$date = date('Y-m-d', strtotime($config['payment_cutoff_date']));
			$this->db->where('payment.InsertDate >', $date);
		}

		$payment_qty_cront = !empty($config['payment_qty_cront'])
			? $config['payment_qty_cront']
			: 10;

		$this->db->limit($payment_qty_cront);
		$this->db->order_by('payment.PaymentID', 'ASC');

		$query = $this->db->get(); // ✅ no need to pass 'payment' here anymore
		return $query->result_array();
	}



	function getAllPaymentsWithBookingAndSupplier1($payment_id = null)
	{
		// Get all columns from payment table
		$this->db->select('payment.*', false);  // Select all columns from payment table

		// Join with booking table (only the necessary columns)
		$this->db->join('booking', 'booking.BookingID = payment.BookingID', 'left');  // LEFT JOIN
		$this->db->select([
			'booking.BookingID',
			'booking.Customer',
			'booking.BookingNumber',
			'booking.InsertDate',  // Example of columns you might want from the booking table
		], false);

		// Join with supplier table (only the necessary columns)
		$this->db->join('supplier', 'supplier.SupplierID = payment.SupplierID', 'left');  // LEFT JOIN
		$this->db->select([
			'supplier.SupplierID',
			'supplier.SupplierName',
			'supplier.SupplierEmail', // Example of columns you might want from the supplier table
		], false);

		// Optional filter by PaymentID
		if (!is_null($payment_id) && $payment_id !== '') {
			$this->db->where('payment.PaymentID', $payment_id);
		}

		$this->load->helper('autocount');
		// Optional filters for syncing payment status (from config settings)
		$config = get_autocount_config(); // get whole autocount config array

		$statuses = !empty($config['payment_sync_autocount_status'])
			? $config['payment_sync_autocount_status']
			: ['P', 'F'];

		$titles = !empty($config['payment_sync_status'])
			? $config['payment_sync_status']
			: ['Y'];

		$this->db->where_in('payment.AutocountSyncStatus', $statuses);
		$this->db->where_in('payment.Status', $titles);
		$this->db->where('payment.AutocountSyncAction IS NOT NULL');

		// Limit the number of payments to retrieve
		$payment_qty_cront = !empty($config['payment_qty_cront'])
			? $config['payment_qty_cront']
			: 25;

		$this->db->limit($payment_qty_cront);

		// Order by PaymentID
		$this->db->order_by('payment.PaymentID', 'ASC');

		// Execute query
		$query = $this->db->get();
return $query->result_array(); // instead of result()
	}

	public function get_payments_by_booking_id($booking_id)
	{
		$this->db->select('p.*');
		$this->db->from('payment AS p');
		$this->db->join('booking AS b', 'b.BookingID = p.BookingID', 'left');
		$this->db->join('supplier AS s', 's.SupplierID = p.SupplierID', 'left');
		$this->db->where('p.BookingID', $booking_id);

		$query = $this->db->get();
		return $query->result_array(); // return as array
	}

	// ============================================
	// Server-Side DataTables Methods
	// ============================================

	// Resolve the admin IDs of the logged-in user's Team(s), used to scope the
	// payment listing for a TEAM LEAD (25) / OP TEAM LEAD (45). A leader may
	// oversee several teams (admin_team), so this unions every member of their
	// primary team and each extra team. Uses a raw query() so it does NOT flush
	// the query-builder state the caller is mid-way through building.
	private function team_member_ids()
	{
		$this->load->helper('team_scope');
		return team_member_ids_from_db($this->db, $this->session->userdata('admin_id'));
	}

	// Role-based row scope shared by the paginated listing + filtered count. Mirrors
	// the booking listing (see Booking_Model::apply_booking_filters). A payment's
	// booking is "assigned" to three people: TC = booking.SalesAgent,
	// TC2 = booking.SalesAgent2, OP = booking.BookingOP.
	//   Individual — SALES AGENT (20) / TC (50): only payments they are personally
	//     assigned to, in ANY of the three slots.
	//   Team — TEAM LEAD (25) / OP (40) / OP TEAM LEAD (45): every payment whose
	//     booking TC, TC2 or OP belongs to their team (cross-team included).
	//   Everyone else (Owner 10, Finance 30, Marketing 60): unscoped.
	private function apply_role_payment_scope()
	{
		$level    = (int) $this->session->userdata('level');
		$admin_id = (int) $this->session->userdata('admin_id');

		if(in_array($level, [20, 50])) {
			$this->db->group_start();
			$this->db->where('booking.SalesAgent', $admin_id);
			$this->db->or_where('booking.SalesAgent2', $admin_id);
			$this->db->or_where('booking.BookingOP', $admin_id);
			$this->db->group_end();
		} elseif(in_array($level, [25, 40, 45])) {
			$team = $this->team_member_ids();
			$this->db->group_start();
			$this->db->where_in('booking.SalesAgent', $team);
			$this->db->or_where_in('booking.SalesAgent2', $team);
			$this->db->or_where_in('booking.BookingOP', $team);
			$this->db->group_end();
		}
	}

	private function apply_payment_filters()
	{
		// Role-based row scope (self / team by SalesAgent or BookingOP)
		$this->apply_role_payment_scope();

		// Supplier filter
		if(!empty($this->input->get('supplier'))) {
			$this->db->where_in('payment.SupplierID', explode(',', $this->input->get('supplier')));
		}

		// Transaction date range filter
		if(!empty($this->input->get('transaction_date'))) {
			$transaction_date = explode(' - ', $this->input->get('transaction_date'));
			$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $transaction_date[0])));
			$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $transaction_date[1])));
			$this->db->where('Date >=', $start_date);
			$this->db->where('Date <=', $end_date);
		}

		// Payment type filter
		if(!empty($this->input->get('payment_type'))) {
			$this->db->where_in('Type', explode(',', $this->input->get('payment_type')));
		}

		// Transaction type filter (PAYMENT IN vs OUT)
		if(!empty($this->input->get('transaction_type'))) {
			$transaction_types = array_map('trim', explode(',', $this->input->get('transaction_type')));
			$has_in = in_array('PAYMENT IN', $transaction_types);
			$has_out = in_array('PAYMENT OUT', $transaction_types);
			if($has_in && !$has_out) {
				$this->db->where('Credit !=', 0.00);
			} else if($has_out && !$has_in) {
				$this->db->where('Credit', 0.00);
			}
		}

		// Reference number filter
		if(!empty($this->input->get('reference_number'))) {
			$this->db->where('ReferenceNumber', $this->input->get('reference_number'));
		}

		// Payment deadline range filter
		if(!empty($this->input->get('payment_deadline'))) {
			$payment_deadline = explode(' - ', $this->input->get('payment_deadline'));
			$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $payment_deadline[0])));
			$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $payment_deadline[1])));
			$this->db->where('Deadline >=', $start_date);
			$this->db->where('Deadline <=', $end_date);
		}

		// Quotation number filter
		if(!empty($this->input->get('quotation_number'))) {
			$this->db->where('QuotationNumber', $this->input->get('quotation_number'));
		}

		// Invoice number filter
		if(!empty($this->input->get('invoice_number'))) {
			$this->db->where('InvoiceNumber', $this->input->get('invoice_number'));
		}

		// Bank filters
		if(!empty($this->input->get('bank'))) {
			$this->db->where('payment.Bank', $this->input->get('bank'));
		}
		if(!empty($this->input->get('bank_account'))) {
			$this->db->where('payment.BankAccount', $this->input->get('bank_account'));
		}
		if(!empty($this->input->get('bank_holder'))) {
			$this->db->where('payment.BankHolder', $this->input->get('bank_holder'));
		}

		// Status filter (only apply if explicitly selected)
		if(!empty($this->input->get('status'))) {
			$statuses = explode(',', $this->input->get('status'));
			$this->db->where_in('payment.Status', $statuses);
		}

		// Booking number filter
		if(!empty($this->input->get('booking_number'))) {
			$this->db->where('BookingNumber', $this->input->get('booking_number'));
		}

		// Customer filter
		if(!empty($this->input->get('customer'))) {
			$this->db->where('Customer', $this->input->get('customer'));
		}

		// Travel date range filter (overlap logic)
		if(!empty($this->input->get('travel_date'))) {
			$travel_date = explode(' - ', $this->input->get('travel_date'));
			$start_date = date('Y-m-d', strtotime(str_replace('/', '-', $travel_date[0])));
			$end_date = date('Y-m-d', strtotime(str_replace('/', '-', $travel_date[1])));
			$this->db->where("((`StartDate` <= '".$start_date."' AND `EndDate` >= '".$end_date."') OR (`StartDate` >= '".$start_date."' AND `StartDate` <= '".$end_date."') OR (`EndDate` >= '".$start_date."' AND `EndDate` <= '".$end_date."'))");
		}

		// Sales agent filter
		if(!empty($this->input->get('sales_agent'))) {
			$this->db->where_in('SalesAgent', explode(',', $this->input->get('sales_agent')));
		}

		// Autocount reference filter
		if(!empty($this->input->get('autocount_reference'))) {
			$this->db->like('payment.AutocountReferenceNumber', $this->input->get('autocount_reference'));
		}

		// Autocount status filter
		if(!empty($this->input->get('autocount_status'))) {
			$this->db->where_in('payment.AutocountSyncStatus', explode(',', $this->input->get('autocount_status')));
		}

		// Exclude deleted payments
		$this->db->where('payment.Status !=', 'N');

		// DataTables global search
		$search_value = $this->input->get('search[value]');
		if(!empty($search_value)) {
			$this->db->group_start();
			$this->db->like('BookingNumber', $search_value);
			$this->db->or_like('Customer', $search_value);
			$this->db->or_like('ReferenceNumber', $search_value);
			$this->db->or_like('supplier.Name', $search_value);
			$this->db->or_like('ReservationNumber', $search_value);
			$this->db->group_end();
		}
	}

	function Read_Payments_Paginated($start, $length, $order_column, $order_dir)
	{
		$this->db->select('PaymentID, payment.BookingID, payment.SupplierID, Date, Type, Credit, ReferenceNumber, Debit, Deadline, payment.BankHolder, payment.Status, BookingNumber, ReservationNumber, Customer, StartDate, EndDate, NetTotal, Token, admin.Name As SalesAgent, booking.SalesAgent AS SalesAgentID, booking.BookingOP, supplier.Name As Supplier, payment.AutocountSyncStatus, payment.AutocountSyncMessage, payment.AutocountSyncAction, payment.AutocountReferenceNumber');
		$this->db->from('booking');
		$this->db->join('payment', 'payment.BookingID = booking.BookingID', 'left');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('supplier', 'supplier.SupplierID = payment.SupplierID', 'left');

		$this->apply_payment_filters();

		$this->db->order_by($order_column, $order_dir);
		$this->db->limit($length, $start);

		return $this->db->get()->result();
	}

	function Count_Payments_Total()
	{
		$this->db->from('payment');
		$this->db->join('booking', 'booking.BookingID = payment.BookingID', 'left');

		// Only apply base role scope (self / team) — mirrors apply_role_payment_scope
		$this->apply_role_payment_scope();
		$this->db->where('payment.Status !=', 'N');

		return $this->db->count_all_results();
	}

	function Count_Payments_Filtered()
	{
		$this->db->from('booking');
		$this->db->join('payment', 'payment.BookingID = booking.BookingID', 'left');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('supplier', 'supplier.SupplierID = payment.SupplierID', 'left');

		$this->apply_payment_filters();

		return $this->db->count_all_results();
	}

	function Calculate_Payment_Summary()
	{
		$this->db->select("SUM(Credit) as total_credit, SUM(Debit) as total_debit");
		$this->db->from('booking');
		$this->db->join('payment', 'payment.BookingID = booking.BookingID', 'left');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('supplier', 'supplier.SupplierID = payment.SupplierID', 'left');

		$this->apply_payment_filters();
		$this->db->where('payment.Status !=', 'R');

		$result = $this->db->get()->row();

		$total_credit = $result->total_credit ?? 0;
		$total_debit = $result->total_debit ?? 0;
		$total_net_profit = $total_credit - $total_debit;

		// Calculate total sales from unique bookings
		$this->db->select('SUM(DISTINCT booking.NetTotal) as total_sales');
		$this->db->from('booking');
		$this->db->join('payment', 'payment.BookingID = booking.BookingID', 'left');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('supplier', 'supplier.SupplierID = payment.SupplierID', 'left');

		$this->apply_payment_filters();
		$this->db->where('payment.Status !=', 'R');

		$sales_result = $this->db->get()->row();
		$total_sales = $sales_result->total_sales ?? 0;

		return array(
			'total_credit' => $total_credit,
			'total_debit' => $total_debit,
			'total_net_profit' => $total_net_profit,
			'total_sales' => $total_sales
		);
	}

	function Read_Supplier_Payments_Breakdown()
	{
		$this->db->select('supplier.SupplierID, supplier.Name, SUM(Debit) as TotalDebit');
		$this->db->from('booking');
		$this->db->join('payment', 'payment.BookingID = booking.BookingID', 'left');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('supplier', 'supplier.SupplierID = payment.SupplierID', 'left');

		$this->apply_payment_filters();

		$this->db->where_in('Type', array('SUPPLIER PAYMENT (DEPOSIT)', 'SUPPLIER PAYMENT (FULL)', 'SUPPLIER PAYMENT (ADDITIONAL)'));
		$this->db->where('payment.Debit >', 0);
		$this->db->where('supplier.SupplierID IS NOT NULL');
		$this->db->group_by('supplier.SupplierID');
		$this->db->order_by('supplier.Name', 'ASC');

		return $this->db->get()->result();
	}

	function Read_Customer_Refunds_Breakdown()
	{
		$this->db->select('payment.BankHolder, SUM(Debit) as TotalDebit');
		$this->db->from('booking');
		$this->db->join('payment', 'payment.BookingID = booking.BookingID', 'left');
		$this->db->join('admin', 'admin.AdminID = booking.SalesAgent', 'left');
		$this->db->join('supplier', 'supplier.SupplierID = payment.SupplierID', 'left');

		$this->apply_payment_filters();

		$this->db->where('Type', 'CUSTOMER REFUND');
		$this->db->where('payment.BankHolder IS NOT NULL');
		$this->db->group_by('payment.BankHolder');
		$this->db->order_by('payment.BankHolder', 'ASC');

		return $this->db->get()->result();
	}

	function Update_Payment_Autocount_Status_Bulk($payment_ids, $status)
	{
		$this->db->where_in('PaymentID', $payment_ids);
		$this->db->update('payment', [
			'AutocountSyncStatus' => $status,
			'AutocountSyncMessage' => null
		]);
		return $this->db->affected_rows() > 0;
	}

	/**
	 * Update AutocountSyncStatus to Pending with reset logic
	 * If status is F: update directly to P
	 * If status is P: update to F first, then to P (to trigger re-sync)
	 * @param array $payment_ids Array of payment IDs to update
	 * @return bool True on success
	 */
	function Update_Payment_Autocount_Status_To_Pending_With_Reset($payment_ids)
	{
		// Get current status for all selected payments
		$this->db->select('PaymentID, AutocountSyncStatus');
		$this->db->where_in('PaymentID', $payment_ids);
		$payments = $this->db->get('payment')->result_array();

		foreach ($payments as $payment) {
			if ($payment['AutocountSyncStatus'] == 'P') {
				// P -> F -> P (intermediate F to reset)
				$this->db->where('PaymentID', $payment['PaymentID']);
				$this->db->update('payment', [
					'AutocountSyncStatus' => 'F',
					'AutocountSyncMessage' => 'Reset from P status'
				]);
			}
			// Now update to P
			$this->db->where('PaymentID', $payment['PaymentID']);
			$this->db->update('payment', [
				'AutocountSyncStatus' => 'P',
				'AutocountSyncMessage' => null
			]);
		}

		return true;
	}

	function Read_Payment_Logs($booking_id)
	{
		$this->db->select('payment_log.Column, payment_log.CurrentData, payment_log.NewData, payment_log.InsertDate, payment.Type as PaymentType, payment.PaymentID, admin.Name as AdminName');
		$this->db->from('payment_log');
		$this->db->join('payment', 'payment.PaymentID = payment_log.PaymentID', 'left');
		$this->db->join('admin', 'admin.AdminID = payment_log.InsertBy', 'left');
		$this->db->where('payment.BookingID', $booking_id);
		$logs = $this->db->get()->result_array();

		// Synthesize a "Created" row per payment so the audit trail shows who
		// created each pay-out / pay-in. payment_log only records updates, but
		// the payment row itself carries InsertBy / InsertDate.
		$this->db->select('payment.PaymentID, payment.Type as PaymentType, payment.InsertDate, admin.Name as AdminName');
		$this->db->from('payment');
		$this->db->join('admin', 'admin.AdminID = payment.InsertBy', 'left');
		$this->db->where('payment.BookingID', $booking_id);
		$payments = $this->db->get()->result_array();

		foreach ($payments as $p) {
			if (empty($p['InsertDate'])) continue;
			$logs[] = array(
				'Column'      => 'Created',
				'CurrentData' => null,
				'NewData'     => null,
				'InsertDate'  => $p['InsertDate'],
				'PaymentType' => $p['PaymentType'],
				'PaymentID'   => $p['PaymentID'],
				'AdminName'   => $p['AdminName'],
			);
		}

		usort($logs, function ($a, $b) {
			return strcmp($b['InsertDate'], $a['InsertDate']);
		});

		return $logs;
	}

}