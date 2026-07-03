package bd.ac.primeuniversity.studentportal.ui.profile

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.data.model.Student
import bd.ac.primeuniversity.studentportal.databinding.FragmentProfileBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemInfoRowBinding
import bd.ac.primeuniversity.studentportal.ui.settings.SettingsActivity

/** Profile tab: header with avatar plus academic and contact info cards. */
class ProfileFragment : Fragment() {

    private var _binding: FragmentProfileBinding? = null
    private val binding get() = _binding!!
    private val app: PrimeApp by lazy { requireActivity().application as PrimeApp }

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
        app.currentStudent.observe(viewLifecycleOwner) { student ->
            student?.let { bind(it) }
        }
    }

    private fun bind(student: Student) {
        binding.avatar.text = student.initials
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

    private fun addRow(container: ViewGroup, label: String, value: String?) {
        if (value.isNullOrBlank()) return
        val row = ItemInfoRowBinding.inflate(layoutInflater, container, false)
        row.rowLabel.text = label
        row.rowValue.text = value
        container.addView(row.root)
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
