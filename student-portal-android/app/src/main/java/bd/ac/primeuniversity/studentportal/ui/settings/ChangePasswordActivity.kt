package bd.ac.primeuniversity.studentportal.ui.settings

import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.databinding.ActivityChangePasswordBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/**
 * Lets the signed-in student or employee change their account password
 * (Settings → Password Change → admin/api/student/change-password.php or
 * admin/api/staff/change-password.php).
 */
class ChangePasswordActivity : AppCompatActivity() {

    private lateinit var binding: ActivityChangePasswordBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityChangePasswordBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.toolbar.setNavigationOnClickListener { finish() }
        binding.btnSave.setOnClickListener { submit() }
    }

    private fun submit() {
        val current = binding.editCurrent.text?.toString().orEmpty()
        val newPass = binding.editNew.text?.toString().orEmpty()
        val confirm = binding.editConfirm.text?.toString().orEmpty()

        binding.inputCurrent.error = null
        binding.inputNew.error = null
        binding.inputConfirm.error = null

        var valid = true
        if (current.isEmpty()) {
            binding.inputCurrent.error = getString(R.string.required)
            valid = false
        }
        if (newPass.length < 8) {
            binding.inputNew.error = getString(R.string.password_too_short)
            valid = false
        }
        if (confirm != newPass) {
            binding.inputConfirm.error = getString(R.string.password_mismatch)
            valid = false
        }
        if (!valid) return

        setLoading(true)
        lifecycleScope.launch {
            when (val result = app.repository.changePassword(current, newPass)) {
                is AppResult.Success -> {
                    Toast.makeText(
                        this@ChangePasswordActivity,
                        R.string.password_changed,
                        Toast.LENGTH_LONG,
                    ).show()
                    finish()
                }
                is AppResult.Error -> {
                    Toast.makeText(
                        this@ChangePasswordActivity,
                        result.message,
                        Toast.LENGTH_LONG,
                    ).show()
                    setLoading(false)
                }
            }
        }
    }

    private fun setLoading(loading: Boolean) {
        binding.btnSave.isEnabled = !loading
        binding.progress.visibility = if (loading) View.VISIBLE else View.GONE
    }
}
