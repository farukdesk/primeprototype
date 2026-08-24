package bd.ac.primeuniversity.studentportal.ui.profile

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.Student
import bd.ac.primeuniversity.studentportal.databinding.FragmentProfileBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemInfoRowBinding
import bd.ac.primeuniversity.studentportal.ui.settings.SettingsActivity
import bd.ac.primeuniversity.studentportal.util.AppResult
import com.bumptech.glide.Glide
import kotlinx.coroutines.launch

/** Profile tab: header with avatar plus academic and contact info cards. */
class ProfileFragment : Fragment() {

    private var _binding: FragmentProfileBinding? = null
    private val binding get() = _binding!!
    private val app: PrimeApp by lazy { requireActivity().application as PrimeApp }

    /** Lets the student pick a new profile photo from the gallery / files. */
    private val pickPhoto =
        registerForActivityResult(ActivityResultContracts.GetContent()) { uri ->
            uri?.let { uploadPhoto(it) }
        }

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?
    ): View {
        _binding = FragmentProfileBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        binding.btnSettings.setOnClickListener {
            startActivity(Intent(requireContext(), SettingsActivity::class.java))
        }
        // Tapping the avatar lets the student upload / replace their photo.
        binding.avatarContainer.setOnClickListener { pickPhoto.launch("image/*") }
        app.currentStudent.observe(viewLifecycleOwner) { student ->
            student?.let { bind(it) }
        }
    }

    private fun bind(student: Student) {
        binding.avatar.text = student.initials
        if (!student.photoUrl.isNullOrBlank()) {
            binding.avatarPhoto.visibility = View.VISIBLE
            Glide.with(this)
                .load(student.photoUrl)
                .circleCrop()
                .into(binding.avatarPhoto)
        } else {
            binding.avatarPhoto.visibility = View.GONE
        }
        binding.name.text = student.fullName
        binding.studentId.text = student.studentId
        binding.dept.text = student.deptName ?: ""

        binding.academicContainer.removeAllViews()
        addRow(binding.academicContainer, "Student ID", student.studentId)
        addRow(binding.academicContainer, "Status", student.status?.replaceFirstChar { it.uppercase() })
        addRow(binding.academicContainer, "Department", student.deptName)
        addRow(binding.academicContainer, "Program", student.programName)
        addRow(binding.academicContainer, "Batch", student.batchName)

        binding.contactContainer.removeAllViews()
        addRow(binding.contactContainer, "Email", student.email)
        addRow(binding.contactContainer, "Phone", student.phone)
    }

    private fun uploadPhoto(uri: Uri) {
        Toast.makeText(requireContext(), "Uploading photo…", Toast.LENGTH_SHORT).show()
        viewLifecycleOwner.lifecycleScope.launch {
            when (val result = app.repository.uploadProfilePhoto(uri)) {
                is AppResult.Success -> {
                    Toast.makeText(requireContext(), "Profile photo updated.", Toast.LENGTH_SHORT).show()
                    // Refresh the session so the new photo shows everywhere.
                    val me = app.repository.me()
                    if (me is AppResult.Success) {
                        app.setSession(me.data.student, me.data.stats)
                    }
                }
                is AppResult.Error ->
                    Toast.makeText(requireContext(), result.message, Toast.LENGTH_LONG).show()
            }
        }
    }

    private fun addRow(container: ViewGroup, label: String, value: String?) {
        if (value.isNullOrBlank()) return
        val row = ItemInfoRowBinding.inflate(layoutInflater, container, false)
        row.rowLabel.text = label
        row.rowValue.text = value
        row.root.startAnimation(
            android.view.animation.AnimationUtils.loadAnimation(requireContext(), R.anim.fade_in_up)
        )
        container.addView(row.root)
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
