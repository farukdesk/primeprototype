<?php
function pp_can_create() {
    return is_super_admin() || can_access('policy-procedure', 'can_create');
}
function pp_can_edit() {
    return is_super_admin() || can_access('policy-procedure', 'can_edit');
}
function pp_can_delete() {
    return is_super_admin() || can_access('policy-procedure', 'can_delete');
}
