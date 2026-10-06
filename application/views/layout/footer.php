            </div>
            <div class="footer bg-white py-4 d-flex flex-lg-column">
                <div class="container-fluid d-flex flex-column flex-md-row align-items-center justify-content-between">
                    <div class="text-dark order-2 order-md-1">
                        <span class="text-muted font-weight-bold mr-2">©2022</span>
                        <a href="<?php echo base_url(); ?>" class="text-dark-75 text-hover-primary">HolidayGoGoGo</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    var HOST_URL = '<?php echo base_url() ?>';
    var KTAppSettings = {
        'breakpoints': {
            'sm': 576,
            'md': 768,
            'lg': 992,
            'xl': 1200,
            'xxl': 1400
        },
        'colors': {
            'theme': {
                'base': {
                    'white': '#FFFFFF',
                    'primary': '#3699FF',
                    'secondary': '#E5EAEE',
                    'success': '#1BC5BD',
                    'info': '#8950FC',
                    'warning': '#FFA800',
                    'danger': '#F64E60',
                    'light': '#E4E6EF',
                    'dark': '#181C32'
                },
                'light': {
                    'white': '#FFFFFF',
                    'primary': '#E1F0FF',
                    'secondary': '#EBEDF3',
                    'success': '#C9F7F5',
                    'info': '#EEE5FF',
                    'warning': '#FFF4DE',
                    'danger': '#FFE2E5',
                    'light': '#F3F6F9',
                    'dark': '#D6D6E0'
                },
                'inverse': {
                    'white': '#FFFFFF',
                    'primary': '#FFFFFF',
                    'secondary': '#3F4254',
                    'success': '#FFFFFF',
                    'info': '#FFFFFF',
                    'warning': '#FFFFFF',
                    'danger': '#FFFFFF',
                    'light': '#464E5F',
                    'dark': '#FFFFFF'
                }
            },
            'gray': {
                'gray-100': '#F3F6F9',
                'gray-200': '#EBEDF3',
                'gray-300': '#E4E6EF',
                'gray-400': '#D1D3E0',
                'gray-500': '#B5B5C3',
                'gray-600': '#7E8299',
                'gray-700': '#5E6278',
                'gray-800': '#3F4254',
                'gray-900': '#181C32'
            }
        },
        'font-family': 'Poppins'
    };
</script>

<script src="<?php echo base_url('assets/js/prismjs-bundle.js'); ?>"></script>
<script src="<?php echo base_url('assets/js/scripts-bundle.js'); ?>"></script>
<script src="<?php echo base_url('assets/js/datatables-bundle.js'); ?>"></script>
<script src="<?php echo base_url('assets/js/column-rendering.js?param=qiqi3'); ?>"></script>
<script src="<?php echo base_url('assets/js/bootstrap-daterangepicker.js?param=qiqi'); ?>"></script>
<script src="<?php echo base_url('assets/js/bootstrap-datepicker.js?param=angie'); ?>"></script>
<script src="<?php echo base_url('assets/plugins/custom/tinymce/tinymce.bundle.js'); ?>"></script>
<script src="<?php echo base_url('assets/js/pages/crud/forms/editors/tinymce.js'); ?>"></script>
<script src="<?php echo base_url('assets/plugins/custom/draggable/draggable.bundle.js'); ?>"></script>
<script src="<?php echo base_url('assets/js/universal-js.js?v=' . filemtime(FCPATH . 'assets/js/universal-js.js')); ?>"></script>
<script src="<?php echo base_url('assets/js/notifications.js?v=' . filemtime(FCPATH . 'assets/js/notifications.js')); ?>"></script>
<script src="<?php echo base_url('assets/js/notifications-page.js?v=' . filemtime(FCPATH . 'assets/js/notifications-page.js')); ?>"></script>
<script src="<?php echo base_url('assets/js/remarks-panel.js'); ?>"></script>

</body>

</html>
