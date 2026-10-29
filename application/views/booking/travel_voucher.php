<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="utf-8">
	<title><?php echo $Title; ?></title>
</head>

<style type="text/css">
	body {
		display: block;
		font-family: Helvetica;
		font-size: 13px;
		padding-left: 10px;
		padding-right: 10px;
	}

	img {
		max-width: 100%;
		height: auto;
	}
	
	h1 {
		font-size: 24px;
		text-align: center;
		margin-top: 0px;
		margin-bottom: 5px;
	}

	h3 {
		font-size: 16px;
		margin: 0px;
		margin-bottom: 5px;
	}

	p {
		margin-top: 0px;
		margin-bottom: 2px;
	}

	.margin-top-25 {
		margin-top: 25px;
	}

	.text-center {
		text-align: center;
	}

	.text-right {
		text-align: right;
	}

	.small-font {
		font-size: 13px;
	}

	a {
		color: black;
		text-decoration: none;
	}
</style>

<body>
	<table style="width:100%;">
		<tr>
			<td style="width: 25%"><img src="<?php echo base_url('assets/image/pdflogo-new.jpeg'); ?>" style="width:180px; max-width:none;"></td>
			<td style="width: 75%">
				<h1><?php echo $CompanyName; ?></h1>
				<p class="text-center small-font">
					<small>(Co. Reg. No. - <?php echo $CompanyRegistrationNumber; ?> | Travel Agent License No. - <?php echo $CompanyLicenseNumber; ?>)</small>
				</p>
				<p class="text-center small-font">
					<small>Address : <?php echo $CompanyAddress; ?></small>
				</p>
				<p class="text-center small-font">
					<small>Website : <?php echo $CompanyWebsite; ?></small>
				</p>
			</td>
		</tr>
	</table>
	<hr class="margin-top-25">
	<table style="width:100%;">
		<tr>
			<th style="width:65%;">
				<h3 class="text-right">TRAVEL VOUCHER</h3>
			</th>
			<th style="width:35%; font-weight:700; text-align:right;">No : <?php echo $BookingNumber; ?></th>
		</tr>
	</table>
	<table style="width:100%; font-size:13px;">
		<tr>
			<td style="width:14%;">Customer</td>
			<td style="width:2%;"> : </td>
			<td style="width:38%;">
				<b><?php echo $Customer; ?></b>
			</td>
			<td style="width:15%;">
				<b>Destination</b>
			</td>
			<td style="width:2%;"> : </td>
			<td style="width:24%;"><?php echo $DestinationName; ?></td>
		</tr>
		<tr>
			<td>Contact No.</td>
			<td> : </td>
			<td><?php echo $CustomerMobile; ?></td>
			<td>
				<b>Reservation No.</b>
			</td>
			<td> : </td>
			<td><?php echo $ReservationNumber; ?></td>
		</tr>
		<tr>
			<td>Travel Date</td>
			<td> : </td>
			<td><?php echo $TravelDate; ?></td>
			<td>Date</td>
			<td> : </td>
			<td><?php echo $InsertDate; ?></td>
		</tr>
		<tr>
			<td>No. Of Guests</td>
			<td> : </td>
			<td><?php echo $PaxNumber; ?></td>
			<td><?php echo $PICLabel; ?></td>
			<td> : </td>
			<td><?php echo $PICText; ?></td>
		</tr>
	</table>
	<hr style="margin-bottom:0px;">
	<table style="width:100%; font-size:13px; border-collapse:collapse;">
		<thead>
			<tr style="font-weight:700;">
				<td style="width:10%; padding:4px 0; border-bottom:1px solid #000;">Item</td>
				<td style="width:80%; padding:4px 0; border-bottom:1px solid #000;">Description</td>
				<td style="width:10%; padding:4px 0; border-bottom:1px solid #000; text-align:center;">Quantity</td>
			</tr>
		</thead>
		<tbody>
			<?php $count = 1;
			foreach($booking_products as $booking_product) { ?>
				<tr style="vertical-align:baseline;">
					<td style="width:10%; padding:10px 0 0 0;"><?php echo $count . '.'; ?></td>
					<td style="width:80%; padding:10px 0 0 0;"><?php if(empty($booking_product->Description)) { echo $booking_product->Name; } else { echo '<u><strong>' . $booking_product->Description . '</strong></u><br>' . $booking_product->Name; } ?></td>
					<td style="width:10%; padding:10px 0 0 0; text-align:center;"><?php if(empty($booking_product->Description)) { echo $booking_product->Quantity; } else { echo '<br>' . $booking_product->Quantity; } ?></td>
				</tr>
			<?php $count++; } ?>
		</tbody>
	</table>
	<br>
	<div><?php echo $TravelVoucherTitle; ?></div>
	<?php if(!empty($TravelVoucherKeyContacts)) { ?>
	<div style="padding-bottom: 10px;">
		<div style="background: #f5f8fc; border-left: 3px solid #4a90d9; padding: 10px 12px; font-size: 13px; page-break-inside: avoid;">
			<strong>Key Contacts:</strong> <?php echo $TravelVoucherKeyContacts; ?>
		</div>
	</div>
	<?php } ?>
	<div><?php echo $TravelVoucherFooter; ?></div>
	<?php if(!empty($TravelVoucherSpecialRemarks)) { ?>
	<div style="padding-top: 8px;">
		<div style="background: #f5f8fc; border-left: 3px solid #4a90d9; padding: 10px 12px; font-size: 13px; page-break-inside: avoid;">
			<strong>Special Remarks:</strong> <?php echo $TravelVoucherSpecialRemarks; ?>
		</div>
	</div>
	<?php } ?>
	
	<div style="border-top:1px dotted #999; margin-top:10px;">&nbsp;</div>
	<p style="font-size:12px; margin-top:0; margin-bottom:2px;">Check your booking status and request E Invoice - <?php echo $CustomerProfileURL; ?></p>
	<p style="font-size:12px; margin-bottom:5px;">E Invoice request must be submitted on/before end of trip.*</p>

	<!-- Guest List Details -->
	<?php
	$has_filled_guests = false;
	foreach($guest_lists as $g) {
		if(!empty(trim($g->GuestFirstName . ' ' . $g->GuestLastName))) {
			$has_filled_guests = true;
			break;
		}
	}
	if($has_filled_guests) { ?>
	<br>
	<div>
		<table style="width:100%; margin-bottom: 8px; border-collapse: collapse;">
			<tr>
				<td style="padding: 0; margin: 0;">
					<h3 style="font-size: 15px; margin: 0; padding: 0; font-weight: 700; text-transform: uppercase;">Guest List</h3>
				</td>
			</tr>
		</table>
		<hr style="border: none; border-top: 2px solid #000; margin: 0 0 0 0;">
		
		<table style="width:100%; font-size:11px; border-collapse: collapse; margin-top: 0;">
			<thead>
				<tr style="background-color: #f8f8f8; page-break-inside: avoid;">
					<th style="padding: 10px 6px; text-align: center; font-weight: 700; width: 4%; border-bottom: 2px solid #000;">#</th>
					<th style="padding: 10px 6px; text-align: left; font-weight: 700; width: 9%; border-bottom: 2px solid #000;">Type</th>
					<th style="padding: 10px 6px; text-align: left; font-weight: 700; width: 20%; border-bottom: 2px solid #000;">Full Name</th>
					<th style="padding: 10px 6px; text-align: left; font-weight: 700; width: 12%; border-bottom: 2px solid #000;">Room</th>
					<th style="padding: 10px 6px; text-align: center; font-weight: 700; width: 9%; border-bottom: 2px solid #000;">Gender</th>
					<th style="padding: 10px 6px; text-align: center; font-weight: 700; width: 14%; border-bottom: 2px solid #000;">Date of Birth</th>
					<th style="padding: 10px 6px; text-align: left; font-weight: 700; width: 17%; border-bottom: 2px solid #000;">IC Number</th>
					<th style="padding: 10px 6px; text-align: left; font-weight: 700; width: 17%; border-bottom: 2px solid #000;">Passport Number</th>
				</tr>
			</thead>
			<tbody>
				<?php 
				$guest_count = 1;
				foreach($guest_lists as $guest) { 
					$full_name = trim($guest->GuestFirstName . ' ' . $guest->GuestLastName);
					if(empty($full_name)) $full_name = '-';
					$dob = !empty($guest->DateOfBirth) ? strtoupper(date('d M Y', strtotime($guest->DateOfBirth))) : '-';
					$ic = !empty($guest->IdentificationNumber) ? strtoupper($guest->IdentificationNumber) : '-';
					$passport = !empty($guest->PassportNumber) ? strtoupper($guest->PassportNumber) : '-';
					$gender = !empty($guest->Gender) ? strtoupper($guest->Gender) : '-';
					
					// Subtle alternating rows
					$row_bg = ($guest_count % 2 == 0) ? '#fafafa' : '#ffffff';
				?>
				<tr style="background-color: <?php echo $row_bg; ?>; page-break-inside: avoid;">
					<td style="padding: 12px 6px; text-align: center; color: #888; font-weight: 600; border-bottom: 1px solid #e8e8e8;"><?php echo $guest_count; ?></td>
					<td style="padding: 12px 6px; font-weight: 600; border-bottom: 1px solid #e8e8e8;"><?php echo $guest->Type; ?></td>
					<td style="padding: 12px 6px; font-weight: 600; border-bottom: 1px solid #e8e8e8;"><?php echo strtoupper($full_name); ?></td>
					<td style="padding: 12px 6px; border-bottom: 1px solid #e8e8e8;"><?php echo !empty($guest->RoomName) ? strtoupper($guest->RoomName) : '-'; ?></td>
					<td style="padding: 12px 6px; text-align: center; border-bottom: 1px solid #e8e8e8;"><?php echo $gender; ?></td>
					<td style="padding: 12px 6px; text-align: center; font-family: 'Courier New', monospace; border-bottom: 1px solid #e8e8e8;"><?php echo $dob; ?></td>
					<td style="padding: 12px 6px; font-family: 'Courier New', monospace; font-size: 10px; border-bottom: 1px solid #e8e8e8;"><?php echo $ic; ?></td>
					<td style="padding: 12px 6px; font-family: 'Courier New', monospace; font-size: 10px; border-bottom: 1px solid #e8e8e8;"><?php echo $passport; ?></td>
				</tr>
				<?php 
				$guest_count++;
				} 
				?>
			</tbody>
			<tfoot>
				<tr style="background-color: #f8f8f8; page-break-inside: avoid;">
					<td colspan="8" style="padding: 12px 6px; text-align: right; border-top: 2px solid #000;">
						<?php 
						$adults = 0; $children = 0; $infants = 0;
						foreach($guest_lists as $g) {
							if($g->Type == 'ADULT') $adults++;
							elseif($g->Type == 'CHILD') $children++;
							elseif($g->Type == 'INFANT') $infants++;
						}
						?>
						<strong style="font-size: 11px; font-weight: 700;">
							Total: <?php echo count($guest_lists); ?> Traveller<?php echo count($guest_lists) > 1 ? 's' : ''; ?>
						</strong>
						<span style="color: #777; font-size: 10px; margin-left: 12px;">
							(<?php echo $adults; ?> Adult<?php echo $adults != 1 ? 's' : ''; ?>, 
							<?php echo $children; ?> Child<?php echo $children != 1 ? 'ren' : ''; ?>, 
							<?php echo $infants; ?> Infant<?php echo $infants != 1 ? 's' : ''; ?>)
						</span>
					</td>
				</tr>
			</tfoot>
		</table>
	</div>
	<?php } ?>
</body>

</html>