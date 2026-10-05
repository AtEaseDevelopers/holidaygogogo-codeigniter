function Confirm_Delete_Record(background, title)
{
	const swalWithBootstrapButtons = Swal.mixin({
		customClass: {
			confirmButton: 'btn btn-light-success m-2',
			cancelButton: 'btn btn-danger m-2'
		},
		buttonsStyling: true
	});
	return swalWithBootstrapButtons.fire({
		width: 550,
		background: `url(${background})`,
		icon: 'warning',
		titleText: `Delete ${title} ?`,
		confirmButtonText: 'Confirm',
		cancelButtonText: 'Cancel',
		showCancelButton: true
	});
}

function Confirm_Delete_Form(form, background, title)
{
	if (form.dataset.deletePending === '1' || form.dataset.deleteSubmitting === '1') {
		return false;
	}
	form.dataset.deletePending = '1';
	Confirm_Delete_Record(background, title).then((action) => {
		delete form.dataset.deletePending;
		if (action.isConfirmed) {
			form.dataset.deleteSubmitting = '1';
			Array.from(form.elements).forEach((field) => {
				if (field.type === 'submit') { field.disabled = true; }
			});
			HTMLFormElement.prototype.submit.call(form);
		}
	});
	return false;
}

function Delete_Record(background, title, url, key, value, status, href)
{
	Confirm_Delete_Record(background, title).then((action) => {
		if(action.isConfirmed) {
			$.ajax({
				url: url,
				type: 'get',
				data: {
					[key]: value,
					status: status
				},
				timeout: 2000,
				success: function() {
					Display_Message(background, `${title} Successfully Deleted`, href);
				},
				error: function() {
					Display_Message(background, `${title} Could Not Be Deleted`, null);
				}
			});
		}
	});
}

function Revert_Booking_Status(background, bookingNumber, revertUrl, fromStatusText, toStatusText)
{
	const swalWithBootstrapButtons = Swal.mixin({
		customClass: {
			confirmButton: 'btn btn-light-success m-2',
			cancelButton: 'btn btn-danger m-2'
		},
		buttonsStyling: true
	});
	swalWithBootstrapButtons.fire({
		width: 550,
		background: `url(${background})`,
		icon: 'warning',
		title: 'Revert Booking Status ?',
		html: `<b>${bookingNumber}</b><br>${fromStatusText} → ${toStatusText}`,
		confirmButtonText: 'Confirm',
		cancelButtonText: 'Cancel',
		showCancelButton: true
	}).then((action) => {
		if(action.isConfirmed) {
			window.location.href = revertUrl;
		}
	});
}

function Display_Message(background, title, url, showConfirmButton = false)
{
	Swal.fire({
		width: 550,
		background: `url(${background})`,
		icon: title.includes('Successfully') || title.includes('No Changes') ? 'success' : 'error',
		title: title,
		showConfirmButton: showConfirmButton,
		...(confirm ? {} : { timer: 2200 })
	}).then(() => {
		if(title.includes('Successfully') || title.includes('No Changes') || title.includes('Page Will Refresh')) {
			window.location.href = url;
		}
	});
}

function Reset(url)
{
	window.location.href = url;
}