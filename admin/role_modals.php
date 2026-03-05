<!-- نافذة إضافة دور جديد -->
<div id="addRoleModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus"></i> إضافة دور جديد</h3>
            <button class="close-modal" onclick="closeModal('addRoleModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="addRoleForm">
                <div class="form-group">
                    <label class="form-label">اسم الدور *</label>
                    <input type="text" name="role_name" class="form-control" required placeholder="مثل: مدير قسم">
                </div>
                <div class="form-group">
                    <label class="form-label">الوصف</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="وصف مختصر للدور"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">الدور الرئيسي (اختياري)</label>
                    <select name="parent_role_id" class="form-control">
                        <option value="">بدون دور رئيسي</option>
                        <?php
                        // جلب الأدوار لاستخدامها كأدوار رئيسية
                        $db = getDB();
                        $roles = $db->query("SELECT * FROM roles ORDER BY role_name")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">
                    <i class="fas fa-save"></i> حفظ الدور
                </button>
            </form>
        </div>
    </div>
</div>

<!-- نافذة تعديل دور -->
<div id="editRoleModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> تعديل الدور</h3>
            <button class="close-modal" onclick="closeModal('editRoleModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="editRoleForm">
                <input type="hidden" name="role_id" id="edit_role_id">
                <div class="form-group">
                    <label class="form-label">اسم الدور *</label>
                    <input type="text" name="role_name" id="edit_role_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">الوصف</label>
                    <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">الدور الرئيسي (اختياري)</label>
                    <select name="parent_role_id" id="edit_parent_role_id" class="form-control">
                        <option value="">بدون دور رئيسي</option>
                        <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">
                    <i class="fas fa-save"></i> حفظ التغييرات
                </button>
            </form>
        </div>
    </div>
</div>

<!-- نافذة إدارة الصلاحيات -->
<div id="permissionsModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3><i class="fas fa-key"></i> إدارة الصلاحيات - <span id="permissions_role_name"></span></h3>
            <button class="close-modal" onclick="closeModal('permissionsModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="permissions_role_id">
            <div class="permissions-grid" id="permissionsContainer">
                <!-- سيتم ملؤها بالجافاسكريبت -->
            </div>
            <div style="margin-top: 20px; text-align: center;">
                <button class="btn btn-success" onclick="savePermissions()" style="padding: 10px 30px;">
                    <i class="fas fa-save"></i> حفظ الصلاحيات
                </button>
            </div>
        </div>
    </div>
</div>

<!-- نافذة إضافة صلاحية -->
<div id="addPermissionModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> إضافة صلاحية جديدة</h3>
            <button class="close-modal" onclick="closeModal('addPermissionModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="addPermissionForm">
                <div class="form-group">
                    <label class="form-label">اسم الصلاحية *</label>
                    <input type="text" name="permission_name" class="form-control" required placeholder="مثل: create_user">
                </div>
                <div class="form-group">
                    <label class="form-label">الوصف</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="وصف الصلاحية"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">
                    <i class="fas fa-save"></i> حفظ الصلاحية
                </button>
            </form>
        </div>
    </div>
</div>

<script>
// دالة عامة لإغلاق النماذج
function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// دالة لفتح نافذة إضافة دور
function openAddRoleModal() {
    document.getElementById('addRoleModal').style.display = 'block';
}

// دالة لفتح نافذة تعديل دور
function openEditRoleModal(role) {
    document.getElementById('edit_role_id').value = role.id;
    document.getElementById('edit_role_name').value = role.role_name;
    document.getElementById('edit_description').value = role.description || '';
    document.getElementById('edit_parent_role_id').value = role.parent_role_id || '';
    document.getElementById('editRoleModal').style.display = 'block';
}

// دالة لفتح نافذة الصلاحيات
function openPermissionsModal(roleId, roleName) {
    document.getElementById('permissions_role_id').value = roleId;
    document.getElementById('permissions_role_name').textContent = roleName;
    
    // جلب الصلاحيات الحالية للدور
    fetch('manage_roles.php?action=get_permissions&role_id=' + roleId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderPermissions(data.permissions, data.role_permissions);
                document.getElementById('permissionsModal').style.display = 'block';
            } else {
                alert('حدث خطأ في جلب الصلاحيات');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('حدث خطأ في الاتصال بالخادم');
        });
}

// دالة لعرض الصلاحيات
function renderPermissions(permissions, rolePermissions) {
    const container = document.getElementById('permissionsContainer');
    let html = '';
    
    permissions.forEach(permission => {
        const isChecked = rolePermissions.includes(permission.id.toString());
        html += `
            <div class="permission-item">
                <input type="checkbox" 
                       id="perm_${permission.id}" 
                       value="${permission.id}"
                       ${isChecked ? 'checked' : ''}>
                <label for="perm_${permission.id}">
                    <strong>${permission.permission_name}</strong>
                    ${permission.description ? `<br><small>${permission.description}</small>` : ''}
                </label>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

// دالة لحفظ الصلاحيات
function savePermissions() {
    const roleId = document.getElementById('permissions_role_id').value;
    const checkboxes = document.querySelectorAll('#permissionsContainer input[type="checkbox"]:checked');
    const permissions = Array.from(checkboxes).map(cb => cb.value);
    
    const formData = new FormData();
    formData.append('action', 'update_permissions');
    formData.append('role_id', roleId);
    formData.append('permissions', JSON.stringify(permissions));
    
    fetch('manage_roles.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            closeModal('permissionsModal');
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال بالخادم');
    });
}

// دالة لفتح نافذة إضافة صلاحية
function openAddPermissionModal() {
    document.getElementById('addPermissionModal').style.display = 'block';
}

// إغلاق النماذج عند النقر خارجها
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// معالجة إضافة دور جديد
document.getElementById('addRoleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'add_role');
    
    fetch('manage_roles.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            closeModal('addRoleModal');
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال بالخادم');
    });
});

// معالجة تعديل دور
document.getElementById('editRoleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'update_role');
    
    fetch('manage_roles.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            closeModal('editRoleModal');
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال بالخادم');
    });
});

// معالجة إضافة صلاحية جديدة
document.getElementById('addPermissionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'add_permission');
    
    fetch('manage_roles.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            closeModal('addPermissionModal');
            location.reload();
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال بالخادم');
    });
});
</script>

<style>
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 0;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    max-height: 80vh;
    overflow-y: auto;
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    color: #2c3e50;
}

.close-modal {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #7f8c8d;
}

.modal-body {
    padding: 20px;
}

.permissions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.permission-item {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.permission-item input[type="checkbox"] {
    margin-left: 10px;
    transform: scale(1.2);
}

.permission-item label {
    cursor: pointer;
    user-select: none;
    flex: 1;
}
</style>
