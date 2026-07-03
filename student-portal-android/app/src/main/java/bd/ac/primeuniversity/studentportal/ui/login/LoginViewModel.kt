package bd.ac.primeuniversity.studentportal.ui.login

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.LiveData
import androidx.lifecycle.MutableLiveData
import androidx.lifecycle.viewModelScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

class LoginViewModel(app: Application) : AndroidViewModel(app) {

    private val appCtx = app as PrimeApp
    private val repo = appCtx.repository

    private val _loading = MutableLiveData(false)
    val loading: LiveData<Boolean> = _loading

    private val _error = MutableLiveData<String?>(null)
    val error: LiveData<String?> = _error

    /** Emits true once login succeeds and the session is populated. */
    private val _success = MutableLiveData(false)
    val success: LiveData<Boolean> = _success

    fun login(login: String, password: String) {
        _error.value = null
        _loading.value = true
        viewModelScope.launch {
            when (val result = repo.login(login, password)) {
                is AppResult.Success -> {
                    appCtx.setSession(result.data.student)
                    _loading.value = false
                    _success.value = true
                }
                is AppResult.Error -> {
                    _loading.value = false
                    _error.value = result.message
                }
            }
        }
    }
}
