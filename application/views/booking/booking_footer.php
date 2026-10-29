<html><head>
	<meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
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
		padding-bottom: 10px;
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

	@page {
        margin: 340px 40px 20px 40px;
    }

    header {
        position: fixed;
        top: -320px;
        left: 0px;
        right: 0px;
        height: 150px;
    }
   /*	@media print{
   		footer { position: static; bottom: 20px; }
   	}*/
   /*	footer { display: block; position: fixed; bottom: 280px; left: 0px; right: 0px; height: 50px; }
   	footer::before { display: none; }*/ 

    .marginontop { margin-top: 220px; }
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
					$logoPath = FCPATH.'assets/image/pdflogo.png';
					$imgData  = base64_encode(file_get_contents($logoPath));
					$imgSrc   = 'data:image/png;base64,'.$imgData;
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
	<?php echo $BookingConfirmationFooter; ?>
</body>
</html>