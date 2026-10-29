<html><head>
	<meta charset="utf-8">
	<title><?php echo $Title; ?></title>
</head><style type="text/css">
	@font-face {
	  font-family: "Segoe UI", Arial, sans-serif !important;
	  font-style: normal;
	  font-weight: normal;
	}
	body {
		/*display: flex;*/
		font-family: "Segoe UI", Arial, sans-serif !important;
		font-size: 13px;
		padding-left: 10px;
		padding-right: 10px;
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

	.margin-top-10 {
		margin-top: 10px;
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
/*	#header{
		align-self: flex-end;
		page-break-inside: avoid;
	}

	#footer{
		align-self: flex-end;
		page-break-inside: avoid;
	}*/

	@page { margin: 80px 40px 20px 40px; }
    header { position: fixed; top: -60px; left: 0px; right: 0px; height: 50px; }
   /*	@media print{
   		footer { position: static; bottom: 20px; }
   	}*/
   /*	footer { display: block; position: fixed; bottom: 280px; left: 0px; right: 0px; height: 50px; }
   	footer::before { display: none; }*/ 

    .marginontop { margin-top: 250px; }
    .items { position: fixed; bottom: 280px; left: 0px; right: 0px; height: 50px; }
    .item0 { margin-top: 550px; }
    .item1 { margin-top: 200px; }
    .item2 { margin-top: 130px; }
    .item3 { margin-top: 60px; }
    .item4 { margin-top: 0px; }
    .split { page-break-after: always; }
	.no-split { page-break-after: avoid; }
</style><body>
	<header>
		<table>
			<tr>
				<?php
					$logoPath = FCPATH.'assets/image/pdflogo-new.jpeg';
					$imgData  = base64_encode(file_get_contents($logoPath));
					$imgSrc   = 'data:image/jpeg;base64,'.$imgData;
				?>
				<td style="width:15%">
					<img src="<?= $imgSrc ?>" style="width:160px;">
				</td>
				<td style="width:85%; text-align: center;">
					<h1><?php echo $CompanyName; ?></h1>
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
		<hr class="margin-top-10">
		<table style="width:100%;">
			<tr>
				<th style="width:65%;">
					<h3 class="text-right"><?php echo $BookingConfirmationTitle; ?></h3>
				</th>
				<th style="width:35%; font-weight:700; text-align:right;">No : <?php echo $BookingNumber; ?></th>
			</tr>
		</table>
		<table style="width:100%; font-size:12px;">
			<tr>
				<td style="width:12%;">Customer</td>
				<td style="width:1%;"> : </td>
				<td style="width:36%;">
					<b><?php echo $Customer; ?></b>
				</td>
				<td style="width:15%;">
					<b>Destination</b>
				</td>
				<td style="width:1%;"> : </td>
				<td style="width:26%;"><?php echo $DestinationName; ?></td>
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
		<table style="width:100%; font-size:13px;">
			<tr style="font-weight:700;">
				<td style="width:5%;">Item</td>
				<td style="width:45%;">Description</td>
				<td class="text-center" style="width:14%;">Item Code</td>
				<td class="text-center" style="width:16%;">Quantity</td>
				<td class="text-center" style="width:10%;">Unit Price <br> (RM)</td>
				<td class="text-center" style="width:10%;">Total <br> (RM)</td>
			</tr>
		</table>
		<hr style="margin-top:0px;">
	</header>
	<div style="back">
	<?php $count = 1;
	foreach($booking_products as $booking_product) { ?>
		<div class="<?php if($count%13 == 1){ echo 'marginontop '; } //add space from top of the header
		if($count%13 == 0) { echo 'split'; } ?>" > <!--make sure item split-->
			<table style="width:100%; font-size:11px; border-spacing:5px;">
				<tr style="vertical-align:baseline;">
					<td style="width:5%;"><?php echo $count . '.'; ?></td>
					<td style="width:45%;"><?php if(empty($booking_product->Description)) { echo $booking_product->Name; } else { echo '<u><strong>' . $booking_product->Description . '</strong></u><br>' . $booking_product->Name; } ?></td>
					<td class="text-center" style="width:14%;"><?php echo $booking_product->ProductCode; ?></td>
					<td class="text-center" style="width:16%;"><?php echo $booking_product->Quantity; ?></td>
					<td class="text-right" style="width:10%;" ><?php echo number_format($booking_product->Price, 2, '.', ','); ?></td>
					<td class="text-right" style="width:10%;"><?php echo number_format($booking_product->Total, 2, '.', ','); ?></td>
				</tr>
			</table>
		</div>

	<?php if($count%13 == 0){ echo '<br>'; } $count++; } ?> <!-- another to make sure item split-->
	</div>
	<div style="position: absolute; top: auto; bottom: 0; ">
		<footer style="">
			<p style="font-size:12px; margin-bottom:5px;">Check your booking status and request E Invoice - <?php echo $CustomerProfileURL; ?></p>
			<p style="font-size:12px; margin-bottom:5px;">E Invoice request must be submitted on/before end of trip.</p>
			<hr style="margin-bottom:5px;">
			<table style="width:100%; margin-bottom:10px;">
				<?php if($Discount != 0.00){ ?>
				<tr>
					<td style="width:32%"><?php if($DepositDeadline !== '-'){ ?>Deposit By : <?php echo $DepositDeadline; ?><br>Deposit Amount : RM <?php echo number_format($DepositAmount, 2, '.', ','); ?><?php } ?></td>
					<td style="width:42%">Full Payment By : <?php echo $FullPaymentDeadline; ?></td>
					<td style="width:16%; border-bottom: 1px solid black;">Subtotal (RM):</td>
					<td style="width:12%; text-align:right; float: left; border-bottom: 1px solid black;"><label><?php echo number_format($Subtotal, 2, '.', ','); ?></label></td>
				</tr>
				<tr>
					<td>&nbsp;</td>
					<td>&nbsp;</td>
					<td style="border-bottom: 1px solid black;">Discount (RM):<br></td>
					<td style="text-align:right; float: left; border-bottom: 1px solid black;"><label>- <?php echo number_format($Discount, 2, '.', ','); ?></label></td>
				</tr>
				<tr style="font-weight:bold;">
					<td>&nbsp;</td>
					<td>&nbsp;</td>
					<td>Total (RM):</td>
					<td style="text-align:right; float: left;"><label><?php echo number_format($NetTotal, 2, '.', ','); ?></label></td>
				</tr>
				<?php }else{ ?>
					<tr style="font-weight:bold;">
						<td style="width:32%"><?php if($DepositDeadline !== '-'){ ?>Deposit By : <?php echo $DepositDeadline; ?><br>Deposit Amount : RM <?php echo number_format($DepositAmount, 2, '.', ','); ?><?php } ?></td>
						<td style="width:42%">Full Payment By : <?php echo $FullPaymentDeadline; ?></td>
						<td style="width:20%;">Total (RM):</td>
						<td style="width:8%; text-align:right; float: left;"><label><?php echo number_format($NetTotal, 2, '.', ','); ?></label></td>
					</tr>
				<?php } ?>
				<tr>
					<td>&nbsp;</td>
					<td>&nbsp;</td>
					<td style="border-bottom: 1px solid black;">Total Paid (RM):</td>
					<td style="text-align:right; float: left; border-bottom: 1px solid black;"><label><?php echo number_format(isset($TotalPaid) ? $TotalPaid : 0, 2, '.', ','); ?></label></td>
				</tr>
				<tr>
					<td>&nbsp;</td>
					<td>&nbsp;</td>
					<td style="border-bottom: 1px solid black;">Outstanding (RM):</td>
					<td style="text-align:right; float: left; border-bottom: 1px solid black;"><label><?php echo number_format(isset($OutstandingBalance) ? $OutstandingBalance : $NetTotal, 2, '.', ','); ?></label></td>
				</tr>
				<tr>
					<td colspan="2"><p style="margin-top:10px;">MayBank 5128-4851-0541 "HolidayGoGoGo Tours Sdn Bhd"</p></td>
					<td>&nbsp;</td>
					<td>&nbsp;</td>
				</tr>
			</table>
			<p style="text-transform:uppercase;"><?php echo $Text; ?></p>
			<hr>
			<br>
			<table class="text-center" style="width:100%;">
				<tr>
					<td style="font-size:12px; text-align:center;">
						<i>This Is Computer Generated Booking Confirmation. No Signature Required.</i>
					</td>
				</tr>
			</table>
		</footer>

	</div>
</body></html>