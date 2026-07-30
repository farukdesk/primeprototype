package bd.ac.primeuniversity.studentportal.ui.login

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.LiveData
import androidx.lifecycle.MutableLiveData
import androidx.lifecycle.viewModelScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.data.local.SecureStorage
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

class LoginViewModel(app: Application) : AndroidViewModel(app) {

    private val appCtx = app as PrimeApp
    private val repo = appCtx.repository

    private val _loading = MutableLiveData(false)
    val loading: LiveData<Boolean> = _loading

    private val _error = MutableLiveData<String?>(null)
    val error: LiveData<String?> = _error

    /**
     * Emits the signed-in role ([SecureStorage.ROLE_STUDENT] or
     * [SecureStorage.ROLE_STAFF]) once login succeeds, null otherwise.
     */
    private val _success = MutableLiveData<String?>(null)
    val success: LiveData<String?> = _success

    /**
     * Tries the student portal first; when that fails, falls back to the
     * staff/employee API so Administrative and Faculty accounts can sign in
     * with the same form.
     */
    fun login(login: String, password: String) {
        _error.value = null
        _loading.value = true
        viewModelScope.launch {
            when (val result = repo.login(login, password)) {
                is AppResult.Success -> {
                    appCtx.setSession(result.data.student)
                    _loading.value = false
                    _success.value = SecureStorage.ROLE_STUDENT
                }
                is AppResult.Error -> {
                    // Not a student account? Try the employee (staff) API.
                    when (repo.staffLogin(login, password)) {
                        is AppResult.Success -> {
                            when (val me = repo.staffMe()) {
                                is AppResult.Success -> {
                                    appCtx.setStaffSession(me.data)
                                    _loading.value = false
                                    _success.value = SecureStorage.ROLE_STAFF
                                }
                                is AppResult.Error -> {
                                    // e.g. an admin account without an employee
                                    // profile – not eligible for the staff view.
                                    repo.clearSession()
                                    _loading.value = false
                                    _error.value = me.message
                                }
                            }
                        }
                        is AppResult.Error -> {
                            _loading.value = false
                            _error.value = result.message
                        }
                    }
                }
            }
        }
    }
}
