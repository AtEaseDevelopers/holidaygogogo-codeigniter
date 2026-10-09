<div class="d-flex flex-column-fluid">
    <div class="container-fluid">
        <div class="card card-custom mb-5">
            <div class="card-header flex-wrap py-3" style="background-color:#D7E2F2;">
                <div class="card-title">
                    <h3 class="card-label" style="color:#6082B6;">
                        <strong>Admin Records</strong>
                    </h3>
                </div>
                <div class="card-toolbar">
                    <a href="<?php echo base_url('Admin/Create'); ?>" class="btn btn-primary font-weight-bold" style="width:180px;">
                        <i class="la la-user"></i>Create Admin
                    </a>
                    <?php $current_url = base_url($_SERVER['REQUEST_URI']); ?>
                </div>
            </div>
            <div class="card-body">
                <div class="accordion accordion-solid accordion-toggle-plus">
                    <div class="card">
                        <div class="card-header">
                            <div id="admin_header" data-toggle="collapse" data-target="#admin_info" class="card-title collapsed" style="font-size:13px;">Filter By Admin Information</div>
                        </div>
                        <div id="admin_info" class="collapse">
                            <div class="card-body">
                                <form action="<?php echo base_url('Admin') ?>" method="get" class="form">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Name</label>
                                                <div class="input-icon">
                                                    <input type="text" name="name" value="<?php if($this->input->get('name')) { echo strtoupper($this->input->get('name')); } else { echo ''; } ?>" autocomplete="off" class="form-control">
                                                    <span>
                                                        <i class="la la-user"></i>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Gender</label>
                                                <select name="gender" class="form-control selectpicker">
                                                    <option selected data-icon="la la-mercury font-size-lg bs-icon" value="">--SELECT GENDER--</option>
                                                    <?php foreach(unserialize(GENDER) as $key => $value) { ?>
                                                        <option <?php if($this->input->get('gender') && $this->input->get('gender') == $key) { echo 'selected'; } ?> data-icon="<?php if($key == 'F') { echo 'la la-female'; } else { echo 'la la-male'; } ?> font-size-lg bs-icon" value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Identification Number</label>
                                                <div class="input-icon">
                                                    <input type="number" name="identification_number" value="<?php if($this->input->get('identification_number')) { echo $this->input->get('identification_number'); } else { echo ''; } ?>" autocomplete="off" class="form-control">
                                                    <span>
                                                        <i class="la la-id-card"></i>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Passport Number</label>
                                                <div class="input-icon">
                                                    <input type="text" name="passport_number" value="<?php if($this->input->get('passport_number')) { echo strtoupper($this->input->get('passport_number')); } else { echo ''; } ?>" autocomplete="off" class="form-control">
                                                    <span>
                                                        <i class="la la-passport"></i>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Mobile</label>
                                                <div class="input-icon">
                                                    <input type="number" name="mobile" value="<?php if($this->input->get('mobile')) { echo $this->input->get('mobile'); } else { echo ''; } ?>" autocomplete="off" class="form-control">
                                                    <span>
                                                        <i class="la la-mobile"></i>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Email</label>
                                                <div class="input-icon">
                                                    <input type="email" name="email" value="<?php if($this->input->get('email')) { echo strtoupper($this->input->get('email')); } else { echo ''; } ?>" autocomplete="off" class="form-control">
                                                    <span>
                                                        <i class="la la-envelope"></i>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Username</label>
                                                <div class="input-icon">
                                                    <input type="text" name="username" value="<?php if($this->input->get('username')) { echo strtoupper($this->input->get('username')); } else { echo ''; } ?>" autocomplete="off" class="form-control">
                                                    <span>
                                                        <i class="la la-user"></i>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Level</label>
                                                <select name="level" class="form-control selectpicker">
                                                    <option selected data-icon="la la-key font-size-lg bs-icon" value="">--SELECT LEVEL--</option>
                                                    <?php foreach(unserialize(LEVEL) as $key => $value) { ?>
                                                        <?php if($this->session->level == 30 && $key == 10) { continue; } ?>
                                                        <option <?php if($this->input->get('level') && $this->input->get('level') == $key) { echo 'selected'; } ?> data-icon="<?php if($key == 20) { echo 'la la-user-alt'; } else if($key == 30) { echo 'la la-hand-holding-usd'; } else { echo 'la la-user-tie'; } ?> font-size-lg bs-icon" value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label>Status</label>
                                                <select name="status" class="form-control selectpicker">
                                                    <option selected data-icon="la la-user-alt font-size-lg bs-icon" value="">--SELECT STATUS--</option>
                                                    <?php foreach(unserialize(ADMIN_STATUS) as $key => $value) { ?>
                                                        <option <?php if($this->input->get('status') && $this->input->get('status') == $key) { echo 'selected'; } ?> data-icon="<?php if($key == 'Y') { echo 'la la-user'; } else { echo 'la la-user-slash'; } ?> font-size-lg bs-icon" value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <input type="submit" value="Filter" class="btn btn-light-success font-weight-bold" style="width:80px;">
                                    <input type="button" id="reset" value="Reset" class="btn btn-light-primary font-weight-bold" style="width:80px;">
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <br><br>
                <div class="dataTables_wrapper dt-bootstrap4 no-footer" <?php if(empty($admins)) { echo 'style="overflow-x:auto;"'; } ?>>
                    <table id="kt_datatable" class="table table-bordered table-head-custom table-checkable dataTable no-footer dtr-inline">
                        <thead>
                            <tr>
                                <th style="text-align:center;">No.</th>
                                <th style="text-align:center;">Name</th>
                                <th style="text-align:center;">Username</th>
                                <?php if($this->session->level == 10) { ?>
                                    <th style="text-align:center;">Password</th>
                                <?php } ?>
                                <th style="text-align:center;">Level</th>
                                <th style="text-align:center;">Team</th>
                                <th class="status" style="text-align:center;">Status</th>
                                <th class="action" style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($admins)) { ?>
                                <td colspan="<?php echo $this->session->level == 10 ? 8 : 7; ?>" style="text-align:center; padding-top:10px; padding-bottom:10px;">Admin Records Not Found</td>
                            <?php } else {
                                $count = 1;
                                foreach($admins as $admin) { ?>
                                    <tr>
                                        <td style="text-align:center; padding-top:15px; padding-bottom:15px;"><?php echo $count; ?></td>
                                        <td style="text-align:center;"><?php echo $admin->Name; ?></td>
                                        <td style="text-align:center;"><?php echo $admin->Username; ?></td>
                                        <?php if($this->session->level == 10) { ?>
                                            <td style="text-align:center;">
                                                <span class="password-mask" id="password_mask_<?php echo $admin->AdminID; ?>">••••••••</span>
                                                <span class="password-value" id="password_value_<?php echo $admin->AdminID; ?>" style="display:none;"><?php echo htmlspecialchars($admin->Password, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <button type="button" class="btn btn-icon btn-light-primary btn-sm" style="width:24px; height:24px; margin-left:5px;" onclick="Toggle_Password(<?php echo $admin->AdminID; ?>, this)" data-toggle="tooltip" title="Show/Hide Password">
                                                    <i class="la la-eye" id="password_icon_<?php echo $admin->AdminID; ?>" style="font-size:14px;"></i>
                                                </button>
                                            </td>
                                        <?php } ?>
                                        <td style="text-align:center;"><?php echo $admin->Level; ?></td>
                                        <td style="text-align:center;"><?php
                                            // Primary team first, then any extra teams a leader oversees.
                                            $team_names = array();
                                            if(!empty($admin->TeamName)) { $team_names[] = $admin->TeamName; }
                                            if(!empty($admin->ExtraTeamNames)) { $team_names[] = $admin->ExtraTeamNames; }
                                            echo $team_names ? html_escape(implode(', ', $team_names)) : '';
                                        ?></td>
                                        <td style="text-align:center;"><?php echo $admin->StatusIcon; ?></td>
                                        <td style="text-align:center;">
                                            <div class="btn-group">
                                                <button type="button" data-toggle="dropdown" class="btn btn-light-primary btn-sm dropdown-toggle" style="padding-left:3px;"></button>
                                                <div class="dropdown-menu">
                                                    <button onclick="Delete_Record('<?php echo base_url('assets/image/sweetalert.jpg'); ?>', '<?php echo 'Admin Record : ' . str_replace('\'', '', $admin->Name); ?>', '<?php echo base_url('Admin/Update_Status_To_N'); ?>', 'admin_id', <?php echo $admin->AdminID; ?>, '<?php echo $admin->Status; ?>', '<?php if(strpos($current_url, '?') == true) { echo base_url('Admin?') . (explode('?', $current_url))[1]; } else { echo base_url('Admin'); } ?>')" class="dropdown-item" style="color:#E37383; font-size:11px;">Delete Admin</button>
                                                    <?php if($admin->Status == 'Y') { ?>
                                                        <button onclick="Deactivate_Or_Activate_Admin('<?php echo 'Deactivate Admin Record : ' . str_replace('\'', '', $admin->Name); ?>', <?php echo $admin->AdminID; ?>, 'Y', 'D')" class="dropdown-item" style="color:#E0115F; font-size:11px;">Deactivate Admin</button>
                                                    <?php } else { ?>
                                                        <button onclick="Deactivate_Or_Activate_Admin('<?php echo 'Activate Admin Record : ' . str_replace('\'', '', $admin->Name); ?>', <?php echo $admin->AdminID; ?>, 'D', 'Y')" class="dropdown-item" style="color:#93C572; font-size:11px;">Activate Admin</button>
                                                    <?php } ?>
                                                    <a href="<?php echo base_url('Admin/Update?admin_id=') . $admin->AdminID; ?>" class="dropdown-item" style="font-size:11px;">Update Admin</a>
                                                    <?php if($this->session->level == 10) { ?>
                                                        <button onclick="Reset_Two_Factor('<?php echo str_replace('\'', '', $admin->Name); ?>', <?php echo $admin->AdminID; ?>)" class="dropdown-item" style="color:#F08C00; font-size:11px;">Reset 2FA</button>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php $count++; }
                            } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    var background = '<?php echo base_url('assets/image/sweetalert.jpg') ?>';
    var url = '<?php echo base_url('Admin/Update_Status_To_D_Or_Y'); ?>';

    <?php if(!empty($this->input->get('name')) || !empty($this->input->get('gender')) || !empty($this->input->get('identification_number')) || !empty($this->input->get('passport_number')) || !empty($this->input->get('mobile')) || !empty($this->input->get('email')) || !empty($this->input->get('username')) || !empty($this->input->get('level')) || !empty($this->input->get('status'))) { ?>
        $('#admin_header').click();
    <?php } ?>

    $('#reset').click(function() {
        Reset('<?php echo base_url('Admin'); ?>');
    });

    $(function() {
        $('[data-toggle="tooltip"]').tooltip();
    });

    function Toggle_Password(admin_id, btn)
    {
        var mask = document.getElementById('password_mask_' + admin_id);
        var value = document.getElementById('password_value_' + admin_id);
        var icon = document.getElementById('password_icon_' + admin_id);
        if(value.style.display === 'none') {
            value.style.display = 'inline';
            mask.style.display = 'none';
            icon.classList.remove('la-eye');
            icon.classList.add('la-eye-slash');
        } else {
            value.style.display = 'none';
            mask.style.display = 'inline';
            icon.classList.remove('la-eye-slash');
            icon.classList.add('la-eye');
        }
    }

    function Reset_Two_Factor(name, admin_id)
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
            title: `Reset 2FA for ${name} ?`,
            text: 'They will set up Google Authenticator again on their next login.',
            confirmButtonText: 'Confirm',
            cancelButtonText: 'Cancel',
            showCancelButton: true
        }).then((action) => {
            if(action.isConfirmed) {
                $.ajax({
                    url: '<?php echo base_url('Admin/Reset_Two_Factor'); ?>',
                    type: 'post',
                    data: { admin_id: admin_id },
                    dataType: 'json',
                    success: function(status) {
                        if(status == true) {
                            Display_Message(background, '2FA Successfully Reset', '<?php echo base_url('Admin') ?>');
                        } else {
                            Display_Message(background, '2FA Could Not Be Reset', null);
                        }
                    },
                    error: function() {
                        Display_Message(background, '2FA Could Not Be Reset', null);
                    }
                });
            }
        });
    }

    function Deactivate_Or_Activate_Admin(title, admin_id, current_status, new_status)
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
            title: `${title} ?`,
            confirmButtonText: 'Confirm',
            cancelButtonText: 'Cancel',
            showCancelButton: true
        }).then((action) => {
            if(action.isConfirmed) {
                $.ajax({
                    url: url,
                    type: 'post',
                    data: {
                        admin_id: admin_id,
                        current_status: current_status,
                        new_status: new_status
                    },
                    dataType: 'json',
                    success: function(status) {
                        if(status == true) {
                            var url = '<?php echo base_url('Admin') ?>';
                            Display_Message(background, 'Admin Record Successfully Updated', url);
                        } else {
                            Display_Message(background, 'Admin Record Could Not Be Updated', null);
                        }
                    },
                    error: function() {
                        Display_Message(background, 'Admin Record Could Not Be Updated', null);
                    }
                });
            }
        });
    }
</script>