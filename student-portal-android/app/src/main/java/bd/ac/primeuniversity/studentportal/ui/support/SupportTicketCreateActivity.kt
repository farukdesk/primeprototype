package bd.ac.primeuniversity.studentportal.ui.support

import android.os.Bundle
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.databinding.ActivitySupportTicketCreateBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/**
 * New IT support ticket form. Students provide a title, category and
 * description; priority and the SLA deadline are set by the server.
 */
class SupportTicketCreateActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySupportTicketCreateBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    private val categories = listOf(
        "Student Finances",
        "Other Student Issues",
        "Hardware",
        "Software",
        "Network",
        "Email",
        "Other",
    )

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySupportTicketCreateBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        binding.categorySpinner.adapter = ArrayAdapter(
            this, android.R.layout.simple_spinner_dropdown_item, categories
        )

        binding.btnSubmit.setOnClickListener { submit() }
    }

    private fun submit() {
        val title = binding.inputTitle.text?.toString()?.trim().orEmpty()
        val description = binding.inputDescription.text?.toString()?.trim().orEmpty()

        binding.titleLayout.error = null
        binding.descriptionLayout.error = null

        if (title.isEmpty()) {
            binding.titleLayout.error = getString(R.string.support_err_title)
            return
        }
        if (description.isEmpty()) {
            binding.descriptionLayout.error = getString(R.string.support_err_description)
            return
        }

        val category = categories[binding.categorySpinner.selectedItemPosition]

        binding.btnSubmit.isEnabled = false
        binding.progress.visibility = View.VISIBLE

        lifecycleScope.launch {
            when (val result = app.repository.createSupportTicket(title, description, category)) {
                is AppResult.Success -> {
                    Toast.makeText(
                        this@SupportTicketCreateActivity,
                        result.data.message ?: getString(R.string.support_submitted),
                        Toast.LENGTH_LONG,
                    ).show()
                    finish()
                    return@launch
                }
                is AppResult.Error -> {
                    Toast.makeText(this@SupportTicketCreateActivity, result.message, Toast.LENGTH_LONG)
                        .show()
                    binding.btnSubmit.isEnabled = true
                    binding.progress.visibility = View.GONE
                }
            }
        }
    }
}
