<?php
$success = $this->session->flashdata('booking_reminder_success');
$error = $this->session->flashdata('booking_reminder_error');
$fields = array(
	'draft_bc_reminder_hours' => array('Draft BC', 'Reminds the booking’s assigned TC2.'),
	'pending_bc_reminder_hours' => array('Pending BC', 'Reminds OP staff and leads who can access the booking.'),
);
?>
<div class="d-flex flex-column-fluid">
	<div class="container-fluid">
		<?php if ($success) { ?>
			<div class="alert alert-light-success" role="status"><?php echo html_escape($success); ?></div>
		<?php } ?>
		<?php if ($error) { ?>
			<div class="alert alert-light-danger" role="alert"><?php echo html_escape($error); ?></div>
		<?php } ?>
		<div class="card card-custom mb-5">
			<div class="card-header">
				<div class="card-title"><h3 class="card-label">Booking Reminders</h3></div>
			</div>
			<form action="<?php echo base_url('Booking_Reminder_Setting/Save'); ?>" method="post">
				<?php if ($can_edit) { ?>
					<input type="hidden" name="token" value="<?php echo html_escape($token); ?>">
				<?php } ?>
				<div class="card-body">
					<p id="booking-reminder-help" class="text-muted">Set how long a booking can stay in each stage before a bell notification is sent. Use 0 to turn off a reminder.</p>
					<div class="row">
						<?php foreach ($fields as $name => list($label, $description)) { ?>
							<div class="form-group col-md-6">
								<label for="<?php echo $name; ?>"><?php echo $label; ?> reminder delay</label>
								<div class="input-group">
									<input id="<?php echo $name; ?>" name="<?php echo $name; ?>" class="form-control" type="number"
										min="0" max="<?php echo $max_hours; ?>" step="1" required
										aria-describedby="booking-reminder-help <?php echo $name; ?>_description"
										value="<?php echo $hours[$name]; ?>" <?php echo $can_edit ? '' : 'readonly'; ?>>
									<div class="input-group-append"><span class="input-group-text">hours</span></div>
								</div>
								<span id="<?php echo $name; ?>_description" class="form-text text-muted"><?php echo $description; ?></span>
							</div>
						<?php } ?>
					</div>
				</div>
				<?php if ($can_edit) { ?>
					<div class="card-footer text-right">
						<button type="submit" class="btn btn-primary font-weight-bold">Save Changes</button>
					</div>
				<?php } ?>
			</form>
		</div>
	</div>
</div>
